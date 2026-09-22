@shipping_labels @ui
Feature: Issuing the labels of a shipment
    In order to send a parcel without opening the carrier's own website
    As an Administrator
    I want to issue the labels of a shipment from the order it belongs to

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And the store has credentials for UPS
        And the store has "UPS Ground" shipping method for UPS service "03"
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And UPS rates service "03" at "$15.40"
        And I am a logged in customer
        And I placed an order "#00000666"
        And I bought a single "PHP Mug"
        And I addressed it to "Ada Lovelace", "500 Pine St", "98101" "Seattle" in the "United States" with identical billing address
        And I chose "UPS Ground" shipping method with "Cash on Delivery" payment
        And I am logged in as an administrator

    Scenario: Issuing the labels of a shipment from its order
        Given UPS issues the labels as "1Z999AA10123456784" in "GIF"
        When I want to ship the order "#00000666"
        And I issue the labels of its shipment
        Then its shipment should have 1 label to download
        And I should not be offered to issue them again
        And UPS should have been asked to issue them once

    Scenario: A parcel that never leaves the country is not declared to anybody
        Given UPS issues the labels as "1Z999AA10123456784" in "GIF"
        When I want to ship the order "#00000666"
        And I issue the labels of its shipment
        Then its shipment should have 1 label to download
        And its shipment should have no customs document to download
