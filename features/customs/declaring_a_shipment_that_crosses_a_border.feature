@customs @ui
Feature: Declaring a shipment that crosses a border
    In order not to have my parcels held at the border
    As an Administrator
    I want customs to be told what is inside, and to be stopped when the catalogue cannot say

    Background:
        Given the store operates on a single channel in "United States"
        And the store also has country "Canada"
        And the store ships from "1 Main St", "Toronto" "M5H 2N2" in the "Canada"
        And the store has credentials for UPS
        And the store has "UPS Worldwide" shipping method for UPS service "08"
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And UPS rates service "08" at "$40.00"
        And UPS issues the labels as "1Z999AA10123456784" in "GIF"
        And I am a logged in customer
        And I placed an order "#00000666"
        And I bought a single "PHP Mug"
        And I addressed it to "Ada Lovelace", "500 Pine St", "98101" "Seattle" in the "United States" with identical billing address
        And I chose "UPS Worldwide" shipping method with "Cash on Delivery" payment
        And I am logged in as an administrator

    Scenario: Handing over the customs document with the labels
        Given product "PHP Mug" is declared as "691200" made in the "Canada"
        When I want to ship the order "#00000666"
        And I issue the labels of its shipment
        Then its shipment should have 1 label to download
        And its shipment should have its customs document to download

    Scenario: Refusing to send what customs cannot be told about
        When I want to ship the order "#00000666"
        And I issue the labels of its shipment
        Then its shipment should have 0 labels to download
        And its shipment should have no customs document to download
        And UPS should not have been asked to issue anything
        And I should be offered to issue them again

    Scenario: Refusing to send an article that is only half declared
        Given product "PHP Mug" is declared as "691200" with nowhere it was made
        When I want to ship the order "#00000666"
        And I issue the labels of its shipment
        Then its shipment should have 0 labels to download
        And UPS should not have been asked to issue anything
