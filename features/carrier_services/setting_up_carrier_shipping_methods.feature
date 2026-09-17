@carrier_services @api
Feature: Setting up carrier shipping methods
    In order to sell shipping at what UPS and FedEx charge
    As an Administrator
    I want to set up a shipping method for a service of a carrier

    Background:
        Given the store operates on a single channel in "United States"
        And I am logged in as an administrator

    Scenario: Setting up a UPS service as a shipping method
        When I want to create a new shipping method
        And I specify its code as "UPS_GROUND"
        And I name it "UPS Ground" in "en_US"
        And I define it for the zone named "United States"
        And I rate it with UPS service "03"
        And I add it
        Then I should be notified that it has been successfully created
        And the "UPS_GROUND" shipping method should be rated with UPS service "03"

    Scenario: Charging a flat amount while the carrier is down
        When I want to create a new shipping method
        And I specify its code as "UPS_GROUND"
        And I name it "UPS Ground" in "en_US"
        And I define it for the zone named "United States"
        And I rate it with UPS service "03"
        And I charge "$12.00" for it in the "WEB-US" channel when the carrier fails
        And I add it
        Then I should be notified that it has been successfully created
        And it should charge "$12.00" in the "WEB-US" channel when the carrier fails

    Scenario: Trying to rate a FedEx method with a service of UPS
        When I want to create a new shipping method
        And I specify its code as "FEDEX_WRONG"
        And I name it "FedEx wrong" in "en_US"
        And I define it for the zone named "United States"
        And I rate it with FedEx service "03"
        And I add it
        Then I should be told that the service is not one of that carrier's
        And there should be no carrier shipping method
