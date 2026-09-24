@shipping_origin @ui
Feature: Giving a channel its own carrier settings
    In order to run each channel the way it needs
    As an Administrator
    I want to set on a channel's shipping origin the carrier settings that differ from the store's configuration

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And I am logged in as an administrator

    Scenario: Seeing what applies to a setting left empty
        When I want to modify the shipping origin of the "United States" channel
        Then I should be told that an empty rate lifetime means the configuration's "900"
        And I should be told that an empty "UPS" label format means the configuration's "GIF"

    Scenario: Giving a channel its own settings
        When I want to modify the shipping origin of the "United States" channel
        And I quote its rates for "1800" seconds
        And I print its "UPS" labels as "ZPL"
        And I add to it the "UPS" service "02" named "UPS 2nd Day Air"
        And I save my changes to the shipping origin
        Then I should be notified that it has been successfully edited
        And the "United States" channel should quote rates for "1800" seconds
        And the "United States" channel should print "UPS" labels as "ZPL"
        And the "United States" channel should add the "UPS" service "02" named "UPS 2nd Day Air"
        But the "United States" channel should keep the status of a shipment for "300" seconds

    Scenario: Leaving every setting to the configuration
        When I want to modify the shipping origin of the "United States" channel
        And I save my changes to the shipping origin
        Then I should be notified that it has been successfully edited
        And the shipping origin of the "United States" channel should say nothing in place of the configuration
        And the "United States" channel should quote rates for "900" seconds
        And the "United States" channel should print "UPS" labels as "GIF"
