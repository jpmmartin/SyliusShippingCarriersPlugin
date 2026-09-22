<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\Order;

use Sylius\Behat\Page\Admin\Order\ShowPage as BaseShowPage;

/**
 * The page of an order in the admin, with what this plugin adds to the shipment of that order.
 *
 * It extends Sylius' own rather than replacing it: everything else on that page is still Sylius'.
 */
class ShowPage extends BaseShowPage
{
    public function issueTheLabels(): void
    {
        $this->getElement('issue_labels_button')->press();
    }

    public function isIssuingOffered(): bool
    {
        return $this->hasElement('issue_labels_button');
    }

    /**
     * How many labels the page offers to download, which is one per package of the shipment.
     */
    public function countLabelsToDownload(): int
    {
        return \count($this->getDocument()->findAll('css', '[data-test-jpmmartin-carrier-label-download-link]'));
    }

    public function cancelTheLabels(): void
    {
        $this->getElement('void_labels_button')->press();
    }

    public function isCancellingOffered(): bool
    {
        return $this->hasElement('void_labels_button');
    }

    public function hasCustomsDocument(): bool
    {
        return $this->hasElement('customs_document_link');
    }

    /**
     * What the carrier said when it would not cancel. It stays on the screen, not only in the message that
     * flashed by, because the labels are still issued and still being billed.
     */
    public function whyTheCarrierWouldNotCancel(): ?string
    {
        if (!$this->hasElement('void_refused')) {
            return null;
        }

        return $this->getElement('void_refused')->getAttribute('data-bs-title');
    }

    /**
     * @return array<string, string>
     */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'customs_document_link' => '[data-test-jpmmartin-carrier-customs-document-download-link]',
            'issue_labels_button' => '[data-test-jpmmartin-carrier-issue-labels-button]',
            'label_download_link' => '[data-test-jpmmartin-carrier-label-download-link]',
            'void_labels_button' => '[data-test-jpmmartin-carrier-void-labels-button]',
            'void_refused' => '[data-test-jpmmartin-carrier-void-refused]',
        ]);
    }
}
