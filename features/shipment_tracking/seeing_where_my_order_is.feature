@shipment_tracking @ui
Feature: Seeing where my order is
    In order to know when my order arrives without looking for it on the carrier's website
    As a Customer
    I want to see on my order what the carrier says about my shipment

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
        And the shipment of this order has tracking number "1Z999AA10123456784"

    Scenario: Seeing the status and the events UPS reports
        Given UPS says the shipment is "Delivered"
        And UPS reports "Delivered" in "Seattle, WA, US"
        And UPS reports "Out for delivery" in "Seattle, WA, US"
        When I view the summary of my order "#00000666"
        Then I should see the tracking number "1Z999AA10123456784"
        And I should see that my shipment is "Delivered"
        And I should see that "Out for delivery" happened

    Scenario: Being given the tracking number while UPS is down
        Given UPS does not answer in time
        When I view the summary of my order "#00000666"
        Then I should see the tracking number "1Z999AA10123456784"
        And I should be told that the status of my shipment is not available
        And I should not see any status of my shipment

    Scenario: A shipment without a tracking number says nothing and asks nothing
        Given the shipment of this order has no tracking number
        And UPS says the shipment is "Delivered"
        When I view the summary of my order "#00000666"
        Then I should not be told anything about where my order is
        And UPS should not have been asked where the shipment is

    Scenario: Opening the same order twice asks UPS once
        Given UPS says the shipment is "In transit"
        And nobody has asked the carriers yet
        When I view the summary of my order "#00000666"
        And I view the summary of my order "#00000666"
        Then I should see that my shipment is "In transit"
        And UPS should have been asked once where the shipment is
