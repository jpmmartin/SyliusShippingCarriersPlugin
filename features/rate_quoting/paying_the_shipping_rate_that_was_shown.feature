@rate_quoting @ui
Feature: Paying the shipping rate that was shown
    In order to trust the price of shipping when I choose it
    As a Customer
    I want my order to be charged the fee shown next to the shipping method

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And the store has credentials for UPS
        And the store has "UPS Ground" shipping method for UPS service "03"
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And I am a logged in customer

    Scenario: Being charged the UPS rate shown next to its shipping method
        Given UPS rates service "03" at "$15.40"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        When I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$15.40"
        When I select "UPS Ground" shipping method
        And I complete the shipping step
        And I select "Cash on Delivery" payment method
        And I complete the payment step
        And I confirm my order
        Then I should see the thank you page
        And my order should have been charged "$15.40" for shipping
