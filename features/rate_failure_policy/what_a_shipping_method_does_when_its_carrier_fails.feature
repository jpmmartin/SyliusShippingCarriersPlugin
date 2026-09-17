@rate_failure_policy @ui
Feature: What a shipping method does when its carrier fails
    In order not to lose sales or give shipping away when UPS has a problem
    As a Store Owner
    I want each shipping method to follow the policy I gave it

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And the store has credentials for UPS
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And I am a logged in customer

    Scenario: Hiding a shipping method while its carrier is down
        Given the store has "UPS Ground" shipping method for UPS service "03"
        And UPS rates service "03" at "$15.40"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        And the store no longer keeps any rate the carriers gave
        And UPS does not answer in time
        When I go to the shipping step
        Then there should be information about no available shipping methods

    Scenario: Charging the flat amount while its carrier is down
        Given the store has "UPS Ground" shipping method for UPS service "03" that costs "$12.00" when UPS fails
        And UPS rates service "03" at "$15.40"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        And the store no longer keeps any rate the carriers gave
        And UPS does not answer in time
        When I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$12.00"

    Scenario: Charging the last rate UPS gave while it is down
        Given the store has "UPS Ground" shipping method for UPS service "03"
        And UPS rates service "03" at "$15.40"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        And I go to the shipping step
        And nobody has asked the carriers yet
        And 20 minutes go by
        And UPS does not answer in time
        When I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$15.40"
        And UPS should have been asked for rates once

    Scenario: Being asked to choose another method when the one I chose is no longer available
        Given the store has "UPS Ground" shipping method for UPS service "03"
        And UPS rates service "03" at "$15.40"
        And I added product "PHP Mug" to the cart
        And I addressed the cart with "Jon Snow" as the billing address
        And I chose "UPS Ground" shipping method and "Cash on Delivery" payment method
        And the store no longer keeps any rate the carriers gave
        And UPS does not answer in time
        When I try to confirm my order
        Then I should be told that the shipping method is not available right now
