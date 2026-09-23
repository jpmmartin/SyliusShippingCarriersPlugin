<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncryptionAwareInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EntityEncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Same shape as Sylius' PaymentBundle EntityEncryptionListener (`@experimental`, so not reused):
 * encrypt what is about to be written, decrypt what was just written or loaded.
 *
 * @template T of EncryptionAwareInterface
 *
 * @internal
 */
final readonly class EntityEncryptionListener
{
    /**
     * @param EntityEncrypterInterface<T> $entityEncrypter
     * @param class-string<T> $entityClass
     */
    public function __construct(
        private EntityEncrypterInterface $entityEncrypter,
        private string $entityClass,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $scheduled = [...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()];
        foreach ($scheduled as $entity) {
            if (!$entity instanceof $this->entityClass) {
                continue;
            }

            $this->entityEncrypter->encrypt($entity);
            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($entity::class), $entity);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();

        foreach ($entityManager->getUnitOfWork()->getIdentityMap()[$this->entityClass] ?? [] as $entity) {
            if ($entity instanceof $this->entityClass) {
                $this->decrypt($entityManager, $entity);
            }
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof $this->entityClass && !$this->decrypt($args->getObjectManager(), $entity)) {
            // Once per load, not after every flush that meets it again: that is where somebody has to look.
            $this->logger->warning('A {class} was loaded that cannot be decrypted with the current encryption key, so it is left encrypted. Its values have to be entered again.', [
                'class' => $this->entityClass,
            ]);
        }
    }

    /**
     * @param T $entity
     *
     * @return bool Whether it could be decrypted
     */
    private function decrypt(EntityManagerInterface $entityManager, object $entity): bool
    {
        try {
            $this->entityEncrypter->decrypt($entity);
        } catch (EncryptionException) {
            // What was stored cannot be read with this key: it was rotated, lost, or the database came from another
            // installation. The entity is left as it was loaded, still encrypted, rather than breaking every
            // request that loads or saves anything afterwards — this listener also runs after every flush. Whoever
            // uses its values has to refuse ciphertext, and the carrier credentials do (CredentialsProvider).
            return false;
        }

        // What Doctrine remembers as loaded is the encrypted data. Left like that, the decrypted
        // values would read as a change and every later flush would rewrite the row with a fresh
        // ciphertext. Remembering the decrypted data instead keeps an untouched entity untouched.
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata = $entityManager->getClassMetadata($entity::class);
        foreach ($metadata->getFieldNames() as $field) {
            $unitOfWork->setOriginalEntityProperty(spl_object_id($entity), $field, $metadata->getFieldValue($entity, $field));
        }

        return true;
    }
}
