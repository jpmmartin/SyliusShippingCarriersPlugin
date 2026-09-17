@rate_failure_policy @ui
Feature: Keeping the checkout working when a carrier fails
    In order to keep shopping when UPS has a problem
    As a Customer
    I want the cart and the checkout never to fail with a server error, whatever UPS does

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And the store has credentials for UPS
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And I am a logged in customer

    Scenario Outline: Going through the checkout of a hidden UPS method when UPS <failure>
        Given the store has "UPS Ground" shipping method for UPS service "03"
        And UPS rates service "03" at "$15.40"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        And I chose "UPS Ground" shipping method and "Cash on Delivery" payment method
        And UPS <failure>
        And the store no longer keeps any rate the carriers gave
        When I go through my cart and every checkout step
        Then none of them should have failed with a server error
        And UPS should have been asked for rates

        Examples:
            | failure                           |
            | does not answer in time           |
            | answers with a server error       |
            | answers with something unreadable |
            | rejects the store's credentials   |

    Scenario Outline: Going through the checkout of a UPS method with a flat amount when UPS <failure>
        Given the store has "UPS Ground" shipping method for UPS service "03" that costs "$12.00" when UPS fails
        And UPS rates service "03" at "$15.40"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        And I chose "UPS Ground" shipping method and "Cash on Delivery" payment method
        And UPS <failure>
        And the store no longer keeps any rate the carriers gave
        When I go through my cart and every checkout step
        Then none of them should have failed with a server error
        And UPS should have been asked for rates

        Examples:
            | failure                           |
            | does not answer in time           |
            | answers with a server error       |
            | answers with something unreadable |
            | rejects the store's credentials   |
