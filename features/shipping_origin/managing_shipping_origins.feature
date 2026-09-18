@shipping_origin @ui
Feature: Managing shipping origins
    In order to have the carriers rate my shipments from where they leave
    As an Administrator
    I want to say where each channel ships from and in what units its catalog is

    Background:
        Given the store operates on a single channel in "United States"
        And I am logged in as an administrator

    Scenario: Saying where a channel ships from
        When I want to add a new shipping origin
        And I ship the orders of the "United States" channel
        And the parcels are sent by "The store", "Ada Lovelace", on "13057800955"
        And I ship from "1 Main St", "Chicago" "60601" in the "United States"
        And I declare the catalog in "Pounds (lb)" and "Inches (in)"
        And I deliver to a "business" unless the buyer says otherwise
        And I add it
        Then I should be notified that it has been successfully created
        And the "United States" channel should ship from "1 Main St", "Chicago" "60601" in "US"
        And its catalog should be in "lb" and "in"
        And it should deliver to a "commercial" unless the buyer says otherwise

    Scenario: Trying to have a channel ship from two places
        Given the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        When I want to add a new shipping origin
        And I ship the orders of the "United States" channel
        And the parcels are sent by "The store", "Ada Lovelace", on "13057800955"
        And I ship from "500 Pine St", "Seattle" "98101" in the "United States"
        And I declare the catalog in "Pounds (lb)" and "Inches (in)"
        And I deliver to a "business" unless the buyer says otherwise
        And I add it
        Then I should be told that the channel already ships from somewhere
        And there should be only one shipping origin

    Scenario: Trying not to say where deliveries go by default
        When I want to add a new shipping origin
        And I ship the orders of the "United States" channel
        And the parcels are sent by "The store", "Ada Lovelace", on "13057800955"
        And I ship from "1 Main St", "Chicago" "60601" in the "United States"
        And I declare the catalog in "Pounds (lb)" and "Inches (in)"
        And I add it
        Then I should be told to say where deliveries go by default
        And the store should still ship from nowhere
