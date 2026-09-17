@rate_quoting @ui
Feature: Seeing carrier rates on the shipping step
    In order to choose how my order is shipped knowing what it costs
    As a Customer
    I want to see the carrier's rate next to each of its shipping methods

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And the store has credentials for UPS
        And the store has "UPS Ground" shipping method for UPS service "03"
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And I am a logged in customer

    Scenario: Seeing the UPS rate next to its shipping method
        Given UPS rates service "03" at "$15.40"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        When I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$15.40"
