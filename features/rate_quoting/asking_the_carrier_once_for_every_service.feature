@rate_quoting @ui
Feature: Asking the carrier once for every service
    In order not to keep the buyer waiting while the carrier is asked over and over
    As a Store Owner
    I want one call to the carrier to rate every one of its shipping methods

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And the store has credentials for UPS
        And the store has "UPS Ground" shipping method for UPS service "03"
        And the store has "UPS Next Day Air" shipping method for UPS service "01"
        And UPS rates service "03" at "$15.40"
        And UPS rates service "01" at "$42.00"
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And I am a logged in customer
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        And the store no longer keeps any rate the carriers gave
        And nobody has asked the carriers yet

    Scenario: Rating every service of a carrier with one call
        When I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$15.40"
        And I should see shipping method "UPS Next Day Air" with fee "$42.00"
        And UPS should have been asked for rates once

    Scenario: Coming back to the shipping step without asking again
        Given I go to the shipping step
        When I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$15.40"
        And UPS should have been asked for rates once
