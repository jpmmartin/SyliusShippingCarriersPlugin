@shipment_packaging @ui
Feature: Managing the box catalog
    In order to be quoted for the boxes the store really ships in
    As an Administrator
    I want to keep a catalog of boxes

    Background:
        Given the store operates on a single channel in "United States"
        And the store ships from "1 Main St", "Chicago" "60601" in the "United States"
        And I am logged in as an administrator

    Scenario: Adding a box to the catalog
        When I want to add a new box to the catalog
        And I call it "Mug box"
        And it holds 12 by 10 by 8 inside
        And it measures 13 by 11 by 9 outside
        And it weighs 0.5 empty and takes 30 at most
        And I add it to the catalog
        Then I should be notified that it has been successfully created
        And the catalog should have a "Mug box" box that holds 12 by 10 by 8

    Scenario: Trying to add a box no carrier would take
        When I want to add a new box to the catalog
        And I call it "Kayak box"
        And it holds 200 by 20 by 20 inside
        And it measures 201 by 21 by 21 outside
        And it weighs 5 empty and takes 30 at most
        And I add it to the catalog
        Then I should be told that the carriers do not take a box that long
        And the catalog should be empty
