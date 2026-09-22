@shipping_labels @ui
Feature: Cancelling the labels of a shipment
    In order not to pay for a parcel that is not going out
    As an Administrator
    I want to cancel the labels of a shipment with the carrier that issued them

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
        And UPS issues the labels as "1Z999AA10123456784" in "GIF"

    Scenario: Cancelling the labels with the carrier that issued them
        Given I want to ship the order "#00000666"
        And I issue the labels of its shipment
        When I cancel the labels of its shipment
        Then UPS should have been told to cancel "1Z999AA10123456784"
        And its shipment should have 0 labels to download
        And I should not be offered to cancel them again
        And I should be offered to issue them again

    Scenario: A carrier that will not cancel leaves the labels issued
        Given UPS refuses to cancel the labels because "parcel already picked up"
        And I want to ship the order "#00000666"
        And I issue the labels of its shipment
        When I cancel the labels of its shipment
        Then the screen should keep saying that the carrier would not cancel because "parcel already picked up"
        And its shipment should have 1 label to download
        And I should still be offered to cancel them
        And UPS should not have been told to cancel anything
