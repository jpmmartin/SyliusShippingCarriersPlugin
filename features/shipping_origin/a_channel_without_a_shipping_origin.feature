@shipping_origin @ui
Feature: A channel without a shipping origin
    In order not to quote shipments the store cannot ship
    As a Store Owner
    I want a channel with no shipping origin to offer none of the carrier's shipping methods

    Background:
        Given the store operates on a single channel in "United States"
        And the store has credentials for UPS
        And the store has "UPS Ground" shipping method for UPS service "03"
        And UPS rates service "03" at "$15.40"
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And I am a logged in customer

    Scenario: Not being offered a carrier's shipping method without a shipping origin
        Given I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        When I go to the shipping step
        Then there should be information about no available shipping methods

    Scenario: Being offered it once the store says where it ships from
        Given the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        When I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$15.40"
