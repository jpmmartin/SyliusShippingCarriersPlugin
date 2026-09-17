@carrier_services @ui
Feature: Managing carrier credentials
    In order to have UPS and FedEx rate my shipments
    As an Administrator
    I want to give the store the credentials of each carrier

    Background:
        Given the store operates on a single channel in "United States"
        And I am logged in as an administrator

    Scenario: Giving the store the credentials of UPS
        When I want to give the store the credentials of UPS
        And I use its "Sandbox (testing)" with the client "ups-client-id" and the secret "ups-client-secret"
        And I hand it the packages by "Scheduled pickup"
        And I add them
        Then I should be notified that it has been successfully created
        And UPS should be called against its sandbox, with packages picked up by "scheduled"

    Scenario: Trying not to choose between the sandbox and production
        When I want to give the store the credentials of UPS
        And I hand it the packages by "Scheduled pickup"
        And I add them
        Then I should be told to choose between the sandbox and production
        And the store should have no carrier credentials

    Scenario: Trying not to say how the packages reach the carrier
        When I want to give the store the credentials of UPS
        And I use its "Sandbox (testing)" with the client "ups-client-id" and the secret "ups-client-secret"
        And I add them
        Then I should be told to say how the packages reach the carrier
        And the store should have no carrier credentials
