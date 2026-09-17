@rate_quoting @ui
Feature: Saying where the order is delivered
    In order to be charged what it really costs to deliver to my door
    As a Customer
    I want to say whether my order goes to a home or to a business

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And the store has credentials for UPS
        And the store has "UPS Ground" shipping method for UPS service "03"
        And UPS rates service "03" at "$15.40"
        And the store has a product "PHP Mug" priced at "$20.00"
        And the product "PHP Mug" has height 8, width 10, depth 6, weight 2
        And the store allows paying with "Cash on Delivery"
        And I am a logged in customer
        And I added product "PHP Mug" to the cart

    Scenario: Being quoted as a delivery to a home
        When I address the cart to a home with "United States" based billing address
        And I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$15.40"
        And UPS should have been asked for a delivery to a home

    Scenario: Being quoted as a delivery to a business
        When I address the cart to a business with "United States" based billing address
        And I go to the shipping step
        Then I should see shipping method "UPS Ground" with fee "$15.40"
        And UPS should have been asked for a delivery to a business
