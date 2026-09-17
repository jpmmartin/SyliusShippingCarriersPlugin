@shipment_packaging @ui
Feature: Restricting the boxes of an origin
    In order to pack with what each warehouse really has
    As an Administrator
    I want a shipping origin to use only some boxes of the catalog

    Background:
        Given the store operates on a single channel in "United States"
        And the catalog has a "Mug box" box
        And the catalog has a "Bottle box" box
        And I am logged in as an administrator

    Scenario: Restricting an origin to one box of the catalog
        When I want to add a new shipping origin
        And I ship the orders of the "United States" channel
        And I ship from "1 Main St", "Chicago" "60601" in the "United States"
        And I declare the catalog in "Pounds (lb)" and "Inches (in)"
        And I deliver to a "business" unless the buyer says otherwise
        And it ships only in the "Mug box" box
        And I add it
        Then I should be notified that it has been successfully created
        And it should ship only in the "Mug box" box
