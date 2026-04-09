# AGENTS.md

## Repository instructions

- This repository contains a WordPress/WooCommerce plugin.
- Preserve runtime behavior exactly unless a prompt explicitly allows otherwise.
- Authentication is immutable. Do not change authentication flow, auth headers, auth storage, endpoint URLs, sender/API key handling, or anything related to current auth behavior.
- Preserve all current nonce action strings, AJAX action names, option keys, hook names, admin page slug, and response payload keys.
- Preserve all current business logic exactly, including:
  - pricing logic
  - price-source selection and fallback order
  - manual Norgespakke logic
  - Bring manual handling logic
  - DSV optimization logic
  - servicepartner behavior
  - SMS-service behavior
  - XML request/response handling
- Structural refactors are allowed only if behavior stays 1:1 identical.
- Prefer small, reversible changes.
- After each task:
  - list touched files
  - state which invariants were preserved
  - run the narrowest relevant validation available
  - stop and wait for the next prompt
- Do not rename public identifiers unless absolutely required for safe extraction, and if so preserve behavior exactly.
- If there is any doubt between cleaner code and exact behavior parity, choose exact behavior parity.


## Cargonizer/Logistra API source-of-truth policy (added 2026-04-08)

- For any API connection/integration behavior related to Cargonizer/Logistra, only use official documentation and precise documented field names from:
  - https://github.com/logistra/cargonizer-documentation/wiki
- Do not invent, rename, or approximate API fields, headers, endpoints, or payload keys.
- If implementation details are uncertain, stop and verify against the official wiki pages before making changes.

## LOGISTRA CARGONIZER API DOCUMENTATION WIKI - FULL TEXT EXPORT

Source wiki: https://github.com/logistra/cargonizer-documentation/wiki
Export basis: current raw markdown from the public GitHub wiki
Page count exported: 13

PAGE ORDER
1. Home | https://github.com/logistra/cargonizer-documentation/wiki | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Home.md
2. Introduction | https://github.com/logistra/cargonizer-documentation/wiki/Introduction | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Introduction.md
3. Definitions | https://github.com/logistra/cargonizer-documentation/wiki/Definitions | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Definitions.md
4. Sending Requests | https://github.com/logistra/cargonizer-documentation/wiki/Sending-Requests | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Sending-Requests.md
5. Authentication | https://github.com/logistra/cargonizer-documentation/wiki/Authentication | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Authentication.md
6. Consignments | https://github.com/logistra/cargonizer-documentation/wiki/Consignments | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Consignments.md
7. Error Handling | https://github.com/logistra/cargonizer-documentation/wiki/Error-Handling | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Error-Handling.md
8. Examples | https://github.com/logistra/cargonizer-documentation/wiki/Examples | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Examples.md
9. Printing | https://github.com/logistra/cargonizer-documentation/wiki/Printing | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Printing.md
10. Service Points | https://github.com/logistra/cargonizer-documentation/wiki/Service-Points | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Service-Points.md
11. Shipping Cost Estimation | https://github.com/logistra/cargonizer-documentation/wiki/Shipping-Cost-Estimation | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Shipping-Cost-Estimation.md
12. Transfers | https://github.com/logistra/cargonizer-documentation/wiki/Transfers | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Transfers.md
13. Transport Agreements | https://github.com/logistra/cargonizer-documentation/wiki/Transport-Agreements | https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Transport-Agreements.md


====================================================================================================
PAGE 1 OF 13
TITLE: Home
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Home.md
====================================================================================================

# Welcome to the Cargonizer API documentation wiki!

## Getting started with Cargonizer APIs

1. Read the [introduction](Introduction)
2. Familiarize yourself with the [transport terminology](Definitions)
3. [Learn](Sending-Requests) how our REST XML/JSON APIs works
4. [Authenticate](Authentication) your self and start developing.


====================================================================================================
PAGE 2 OF 13
TITLE: Introduction
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Introduction
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Introduction.md
====================================================================================================

The business of shipping is a complicated one, but we've done everything we can to make it easy. Any questions? Please [contact us](https://logistra.no/kontakt-oss).

If you're developing a webshop, some shipping information may be presented to the buyers whilst other shipping operations is performed during order management. Our API resources should provide you with everything you need for a complete freight solution in your webshop. 

Ordersystems, warehouse solutions, or anything else? What about a one-click shipment booking functionality? Many developers of such systems, has done this already, using our API resources. When you're done, you have access to 600+ carriers in your own system. That's alot better than 600 different API integrations...


### Suggested steps:

* Present a list of all shipping methods to let you or your customer select from.
  * [Transport Agreements](Transport-Agreements)

* Present a list of possible pickup-points/delivery-stores if the shipping product requires one. 
  * [Service Points](Service-Points)

* Show the freight costs
  * [Shipping-Cost-Estimation](Shipping-Cost-Estimation)

* Create a consignment, providing the shipping addresses, the selected shipping method and parcel information.
  * [Consignments](Consignments)

* Print the various labels and manifests that accompany the parcels
  * [Printing](Printing)

* When ready, mark the consignment as ready for transfer. The necessary information is sent to the carrier
  * [Transfers](Transfers)


====================================================================================================
PAGE 3 OF 13
TITLE: Definitions
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Definitions
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Definitions.md
====================================================================================================

These definitions are used throughout the documentation and are entities you will be coming across as you implement an application using the API.

### User
Your user account. Everything you do in Cargonizer you do as a user, and you authenticate your access to it using a password or an API key.

### Consignor
Also referred to as Sender. Most likely the same as your company. It is a basic entity, like your user account; your user account can be associated with more than one sender , and most actions you take in Cargonizer happen in the context of a sender. You create a consignment for a sender, and that sender becomes the consignor for that consignment.

### Consignment
A shipment containing all information about the nature of the cargo, detailed parcel information, transport services and addresses of different parties involved in the shipment. A shipment can have many individual parcels, referred to as parcels, items of pieces in Cargonizer (yes, we know it can be confusing, we're working on it).

### Parties/Party
A company or person with a role in the consignment/shipment. Examples are consignor, consignee, third-party freight payer, delivery part, pick up address.

### Consignee

The party that receives the consignment. The name and address of the receiving part.

### Carrier

A carrier is the party responsible for actually moving your consignment from its origin to its destination. Exmples of carriers are Bring and Postnord.

### Product

Each carrier offers different products. They can have different priorities, freight prices and methods for delivery. Examples are Home Delivery, Service Parcels, Express, Road Groupage.

### Transport Agreement

Each Consignor (sender) needs to have an agreement with the chosen carrier before shipping. The Consignor has at least one agreement number with each of the carriers they will use for transport of goods. The information about the shipment will be sent electronically to the carrier. The receiving systems at the carriers side needs to know who is sending this information. The transport agreement number is often used for this purpose


====================================================================================================
PAGE 4 OF 13
TITLE: Sending Requests
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Sending-Requests
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Sending-Requests.md
====================================================================================================

Cargonizer has an HTTP REST API.

### Base URL

`https://api.cargonizer.no`

### Path / Endpoint

The path section determines which endpoint and resource type you're working with. For example, the URL

`https://api.cargonizer.no/customers`

is used to work with the Customers endpoint. Referring to a specific resource is done by adding that resource's ID to the URL:

`https://api.cargonizer.no/customers/12345`

## HTTP Methods

The HTTP method determines the action you are performing on the endpoint/resource:

|HTTP Method|Action|
|---|---|
|GET|Retrieve a resource|
|POST|Create a resource|
|PATCH| Update a resource|
|DELETE|Delete a resource|


## Formats

You will encounter two main data formats using our API: XML and JSON. XML will eventually be phased out, and all new endpoints will use JSON.

### Specifying format

#### Response

The format of the response data is specified either by using an extension on the endpoint or by using the `Accept` header.

```
GET /customers.xml
```

```
GET /customers
Accept: application/xml
```

#### Request

When sending data in a `POST` or `PATCH` request, the format of that data is specified using the `Content-Type` header.

```
POST /customers
Content-Type: application/xml

<customer>
  ...
</customer>
```

#### Request + Response

Combining the two, you specify the format of the data you're sending and the format of the data you will receive.

```
POST /customers.xml
Content-Type: application/xml

<xml/>
```

```
POST /customers
Accept: application/xml
Content-Type: application/xml

<xml/>
```

### Extensions and Media Types

|Format|Extension|Media Type|
|---|---|---|
|JSON|.json|application/json|
|XML|.xml|application/xml|


## Query String Parameters

The query string part of the URL can be used to supply parameters that alter the meaning of the request in some way.

`GET /customers?country=SE&postcode=55600`

Map and list parameters can be supplied using a custom syntax.

### Maps

Map values are encoded using `[` and `]`

In the query string

`GET /carOfTheYear?car[name]=Volvo+Amazon&car[stats][model]=1959&car[stats][color]=black&car[discontinued]=true`

The parameters correspond to this JSON document:

```json
{
  "car": {
    "name": "Volvo Amazon",
    "stats": {
      "model": "1959",
      "color": "black"
    },
    "discontinued": "true"
  }
}
```

### Lists

Any parameter, including those in maps, can be specified to be part of a list by adding `[]` at the end of the name.

`GET /carOfTheYear?car[notes][]=three-point+seatbelt&car[notes][]=timeless+beauty`

```json
{
  "car": {
    "notes": [
      "three-point seatbelt",
      "timeless beauty"
    ]
  }
}
```


====================================================================================================
PAGE 5 OF 13
TITLE: Authentication
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Authentication
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Authentication.md
====================================================================================================

To access most resources, you must be authenticated. Authentication is done using an API key associated with your user account; additionally, many resources require that you tell them which sender you represent. Both the API key and sender ID are passed as custom HTTP headers

## Authenticating your request using your API key
To authenticate your request, you must supply your API key, a long string of numbers and letters (hexadecimal representation of an integer) that you can find by going to the [Preferences](https://cargonizer.no/profile) pane when you're logged into the Cargonizer web interface. This key is associated with your user account and is a secret (treat it like a password). 

You need an Cargonizer Account to get access to you API key. Please [choose a plan and register](https://formcrafts.com/a/zugtwyk) first, if that's not the case. If you are a developer you can get a test account in our test environment (sandbox) by contacting us [here](https://www.logistra.no/kontakt-oss).

The HTTP header containing your API key must be named X-Cargonizer-Key, as in the examples below.

Example: Requesting your user profile as an XML document

<sub>_cURL_</sub>
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' 'https://api.cargonizer.no/profile.xml'
```
<sub>_HTTP_</sub>
```
GET /profile.xml HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'GET';
http.url = 'https://api.cargonizer.no/profile.xml';
http.headers.add('X-Cargonizer-Key', '12345');
response = http.execute();
```

## Supplying a sender ID

In a lot of cases, Cargonizer needs to know which sender you're operating as, as it's possible for one user to have access to more than one sender. When using the API, you supply the sender's ID using an HTTP header just like you supply your API key. You can find a list of sender IDs for your user in the web interface under [Preferences](https://cargonizer.no/profile), just like your API key.

Note: While we refer to it as a sender ID in this and other documents, the ID you must supply actually represents the relationship between your user and a sender. Thus, the "sender ID" for a sender is different for each of that sender's associated users.

The HTTP header containing the sender ID must be named
```
X-Cargonizer-Sender
```

Example: Requesting a list of transport agreements for a sender
<sub>_cURL_<sub>
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' 'https://api.cargonizer.no/transport_agreements.xml'
```

<sub>_HTTP_</sub>
```
GET /transport_agreements.xml HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345
X-Cargonizer-Sender: 678
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'GET';
http.url = 'https://api.cargonizer.no/transport_agreements.xml';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('X-Cargonizer-Sender', '678');
response = http.execute();
```
**Note**: For a resource that requires a sender ID, you will also have to supply an API key. All requests that happen in the scope of a sender must be authenticated by a user allowed to act as that sender.


====================================================================================================
PAGE 6 OF 13
TITLE: Consignments
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Consignments
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Consignments.md
====================================================================================================

|||
|---|---|
|Endpoint|/consignments.xml|
|[Formats](Sending-Requests#formats)|XML|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|Yes|


## Create Consignments
The consignment is what most of the system revolves around. Most other resources are related to consignments in one way or the other. It represents one or more physical parcels that are to be transported, containing information about the parties involved (consignee, consignor), who's transporting it (the carrier), which of the carrier's products you're using and any additional services you want to attach to the consignment.


## Usage
Creating a consignment

<sub>_cURL_</sub>
```bash
curl -g -XPOST -d@consignment.xml -H'Content-Type: application/xml' -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' 'https://api.cargonizer.no/consignments.xml'
```

<sub>_HTTP_</sub>
```bash
POST /consignments.xml HTTP/1.1
Host: cargonizer.no
Content-Type: application/xml
X-Cargonizer-Key: 12345
X-Cargonizer-Sender: 678
<contents of file "consignment.xml">
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.no/consignments.xml';
http.headers.add('Content-Type', 'application/xml');
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('X-Cargonizer-Sender', '678');
http.body = new File('consignment.xml').read();
response = http.execute();
```

## XML
```xml
<!--Always begin with a <consignments> element. It is required.-->
<consignments>
    <consignment transport_agreement="1433" print="false" estimate="false">
        <!--
            transport_agreement:    Required. Unique per consignor. 
                                    You can use the transport_agreement resource to get a valid 
                                    list of agreements or contact Logistra if this is out of your reach. 

            print:                  (Deprecated) It is recommended to implement and use our printing API 
                                    and keep this set to "false". Using our printing API will give you a 
                                    better control and it is imperative to use the printing api if the 
                                    sender has more than one DirectPrint.

            estimate:               (Deprecated) Must be set to "false".
        -->
        <booking_request>false</booking_request>
        <!--
            Optional. By setting this value to “true”, you will inform the carrier 
            to do a physical pickup from your consignors address. 
            Default value is “false”, meaning no pickup is initiated based on your 
            consignment information you send to the carrier.
        -->
        <email_label_to_consignee>false</email_label_to_consignee>
        <!--
            Optional only when generating return shipments. By setting this value to “true”, 
            you will send the return label as a pdf file to the conisignee by e-mail. 
            Default value is “false”, meaning that no e-mail is sent.
        -->
        <email-notification-to-consignee>false</email-notification-to-consignee>
        <!--
            Optional. By setting this value to “true”, Cargonizer will send the track&trace link 
            to the consignee by e-mail when the consignment is transferred to the carrier. 
            Default value is “false”, meaning that no e-mail is sent.
        -->
        <product>mypack</product>
        <!--
            Required. An identifier that specifies the transport product you want to use. 
            transport_agreements resource will give you a list to choose from. 
            For more details, see this list of valid identifiers
        -->
        <values>
            <!--
                To be used freely. You make up your own key-value pairs so you can refer to 
                your consignment from your own data. The same elements will be passed back to you. 
                Feel free to use it as you like, but please include a value "provider" tag 
                identifying you as the provider of this xml. See examples and more info below.
            -->
            <value name="provider" value="API Company AS"/>
            <value name="provider-email" value="api@apicompany.no"/>
        </values>
        <parts>
            <!--
            Every party on the consignment comes under this element. 
            All elements are identical for each party unless otherwise stated. See below.
            Possible parties are:

            <consignee>             Required. The party that will receive the packages.

            <service_partner>       Optional. Required if using Postnord ServicePoint/Parcel Locker product. 
                                    The pick-up point that this consignment will be delivered to. 
                                    Consignee will pick up the parcels at this address. 
                                    Use the service_partners.xml to get a correct servicepartner.

            <return_addres>         Optional. A xml received without a return address will null the 
                                    return-to data that is registered back-end. In most cases 
                                    this carrier then will use the consignor part as return address, 
                                    but not always. Adding a specific return address will then 
                                    make sure all returns are returned correctly.
            
            <freight_payer_address> Optional. Only to be used when an external party pays freight. 

            <pickup_address>        Optional. The party where the packages are to be collected by the carrier. 
                                    This part is not available for all carriers.
             -->
            <consignee freight_payer="false">
                <!--
                    freight_payer:  Optional. Value is “true” if consignee is to pay for the shipping costs.
                                    “false” is the default value. If set to “false” or not spesified, 
                                    consignor or another part will be used as freight payer. 
                -->
                <number>
                    <!-- Optional. The consignors own ID for this party. -->       
                </number>
                <name>
                    <!-- Required. Name of the Consignee. -->
                </name>
                <address1>
                    <!-- Optional. Street address. -->
                </address1>
                <address2>
                    <!-- Optional. Additional address information. -->
                </address2>              
                <postcode>
                    <!-- Required -->
                </postcode>
                <city>
                    <!-- Required -->         
                </city>
                <country>
                    <!-- Required. Only ISO 3166-1 (2-alpha) is supported. -->          
                </country>
                <email>
                    <!-- Optional. The email address. -->           
                </email>
                <mobile>
                    <!-- Optional. The mobile phone number. -->             
                </mobile>
                <contact-person>
                    <!-- Optional. Contact person. Address attention. -->
                </contact-person>
                <customer-number>
                    <!--    Optional. The agreement number between this part and its carrier. 
                            Only required when this part is freightpayer. 
                    -->              
                </customer-number>
            </consignee>
            <service_partner>
                <number>
                    <!--  Required. Number used to identify the partner. -->
                </number>
                <customer-number>
                    <!--  Required when shipping abroad with a Bring product 
                          that is eglibe for pickup point/service point. 
                          Example value: c===0010
                    -->
                </customer-number>
                <name>Extra Brandbu</name>
                <address1>Torgvegen 3</address1>
                <postcode>2760</postcode>
                <city>BRANDBU</city>
                <country>NO</country>
            </service_partner>
            <freight_payer_address>
                <customer-number>
                    <!-- Required. The cusomer number known to the carrier. -->             
                </customer-number>
                <!-- rest of elements identical to other parties --> 
            </freight_payer_address>
        </parts>
        <items>
            <!-- 
                Required. Each parcel comes under this node.
             -->
            <item type="package" amount="1" weight="1" volume="15" description="Varer">
            <!--
                    type:           Required. Type of parcel. Available parcel types for each carrier 
                                    and product are found in the response of transport-agreements.xml.
                    
                    amount:         Required. Number of parcels. 
                                    Cargonizer will generate this amount of labels.

                    weight:         Conditional. The total weight of the parcel(s). 
                                    Must be specified for some transport products.

                    volume:         Conditional. The total volume of the parcel(s) in dm3. 
                                    Must be specified for some transport products.

                    length:         Optional. The total length of the parcel in cm. 
                                    Not useful if the amount attribute is greater than 1.

                    height:         Optional. The total height of the parcel in cm. 
                                    Not useful if the amount attribute is greater than 1.

                    width:          Optional. The total width of the parcel in cm. 
                                    Not useful if the amount attribute is greater than 1.

                    load-meter:     Optional. Load Metres of the parcel in metres.

                    description:    Optional. A description of the content inside this parcel(s).
                -->
                <dangerous_goods  amount="2" gross_weight="2" labels="3.1" net_weight="1" 
                                    description="WETTED with not less than 40% water, or mixture of alcohol and water, by mass" 
                                    name="MANNITOL HEXANITRATE (NITROMANNITE)"                                     
                                    packing_group="2" points="50" tunnel_code="(B/C)" 
                                    type="Plastkanne" un_number="3421"/>
                <!-- Only to be used if item contains dangerous goods (ADR)
                    name:           Required. Also referred to as technical name.
                    type:           Optional. Description of the packing
                    amount:         Optional. Number of GDS items.
                    description:    Optional. Additional ADR information/n.o.s etc.
                    un_number:      Required. International UN number 
                    tunnel_code:    Required. Tunnel code
                    labels:         Required. Also referred to as hazard class
                    packing_group:  Optional. Valid values: 1, 2, 3 or in Roman numerals (I, II, III)
                    gross_weight:   Optional. Gross weight of ADR items
                    net_weight:     Required. Net weight of ADR items
                    points:         Optional. Points
                -->   
            </item>   
        </items>
        <!-- NVIT
             https://mailchi.mp/d0de974335ed/bytt-til-fraktlsning-fra-logistra-og-spar-kostnader-14196304
             https://mailchi.mp/aaf89673bc50/bytt-til-fraktlsning-fra-logistra-og-spar-kostnader-14196206

             A <consignment> may contain several <nvit> elements, each element describing
             a particular type of item.

             quantity:       Required; integer. Quantity of customs articles.
             number:         Required;  string. HS customs number. 6-10 digits.
             description:    Required;  string. Description of item(s).
             gross-weight:   Required;   float.
             net-weight:     Optional;   float.
             origin-country: Optional;  string. ISO 3166-1 alpha-2 country code.
             currency:       Optional;  string. ISO 4217 3-letter currency code.
             amount:         Optional;   float. Amount of `currency`.
        -->
        <nvit
          quantity="5"
          number="123456"
          description="5 hand-rolled Macanudo cigars"
          gross-weight="0.5"
          net-weight="0.5"
          origin-country="CU"
          currency="CUP"
          amount="1200.00"
        />
        <services>
            <!-- Optional. Additional transport services must be specified here. Not all transport products supports services. -->
            <service id="postnord_notification_email" />
            <service id="postnord_notification_sms" />
            <!-- 
                id:     Required. An ID that identifies the service. Use transport_agreements resource to see id of possible services.
            -->
        </services>
        <references>
            <consignor><!-- Senders ref / typical order number --></consignor>
            <consignee><!-- Receivers ref / Consignee reference --></consignee>
        </references>
        <messages>
            <carrier><!-- Optional. Message to carrier. --></carrier>
            <consignee><!-- Optional. Message to consignee. --></consignee>
        </messages>
    </consignment>
</consignments>
```

## Using the \<values\> element
The concept with this element is to let you specify freely chosen name – value pair that is guaranteed to be returned identically back in the response XML. By using this element you will establish a connection between the information you send in and the information that is returned back to you. Look at it as the link between the XML request and the XML response.

In order to identify the provider of the xml we also would like if you added your company information here.

Here is an example, where first the provider is presented and the next tag is named orderno, and 123 is the orderno. We have also added a delivery number with the value 8765.

```xml
<values>
  <value name="provider" value="Logistra AS" />
  <value name="provider-email" value="post@logistra.no" />
  <value name="orderno" value="123" />
  <value name="deliveryno" value="8765" />
</values>
```

## Terms of Delivery
Some shipping products require that Terms of Delivery (TOD) must be specified. This information consists of 4 attributes attached to a `tod` element inside the `consignment` element.

```xml
<consignment>
  ...
  <tod code="EXW" country="SE" postcode="100 05" city="Stockholm"/>
  ...
</consignment>
```
### List of valid TOD codes

|code|descripton|
|---|---|
|EXW|Ex Works|
|FCA|Free Carrier seller's premises|
|CPT|Carriage Paid To buyer's premises|
|CIP|Carriage and Insurance Paid to buyer's premises|
|DAT|Delivered At Terminal|
|DAP|Delivered At Place|
|DDP|Delivered buyer's premises Duty Paid|
|FAS|Free alongside ship|
|FOB|Free on board|
|CFR|Cost and freight|
|CIF|Cost insurance and freight|

## Examples
Here is an example of a very minimalistic XML
```xml
<consignments>
    <consignment transport_agreement="1" print="false">
     <values>
	 <value name="provider" value="Logistra AS" />
         <value name="provider-email" value="post@logistra.no" />
     </values>
    <product>tg_stykkgods</product>
    <parts>
        <consignee>
            <name>Juan Ponce de Leon</name>
            <postcode>1337</postcode>
            <city>Sandvika</city>
            <country>NO</country>
        </consignee>
    </parts>
    <items>
        <item type="package" amount="1" weight="2.54" volume="3" description="Something"/>
    </items>
    </consignment>
</consignments>
```

Here is an example of a absolute minimal XML.
```xml
consignments>
  <consignment transport_agreement="1" print="false">
    <product>tg_stykkgods</product>
      <parts>
        <consignee>
          <name>Juan Ponce de Leon</name>
          <postcode>1337</postcode>
          <city>Sandvika</city>
          <country>NO</country>
        </consignee>
      </parts>
    <items>
      <item type="package" amount="1" weight="2.54"/>
    </items>
  </consignment>
</consignments>
```

Here is a more completed XML variant
```xml
<consignments>
  <consignment transport_agreement="1" estimate="true" print="false">
    <values>
      <value name="provider" value="Logistra AS" />
      <value name="provider-email" value="post@logistra.no" />
      <value name="order" value="123" />
      <value name="humbaba" value="enkidu" />
    </values>
    <transfer>true</transfer>
    <booking_request>true</booking_request>
    <product>tg_stykkgods</product>
    <parts>
      <consignee>
        <name>Juan Ponce de Leon</name>
        <postcode>1337</postcode>
        <address1>Street address</address1>
        <city>Sandvika</city>
        <country>NO</country>
        <address1>Street 5</address1>
        <mobile>98989898</mobile>
        <contact-person>Juan</contact-person>
      </consignee>
      <return_address>
        <name>Company Inc.</name>
        <address1>Street 10</address1>
        <postcode>1337</postcode><!-- Lookup -->
        <country>NO</country>
      </return_address>
    </parts>
    <items>
      <item type="package" amount="1" weight="2.54" volume="3" description="Something"/>
      <item type="package" amount="1" weight="22" volume="122" description="Something else"/>
    </items>
    <services>
      <service id="insurance">
        <currency>NOK</currency>
        <amount>500</amount>
      </service>
    </services>
    <references>
      <consignor>Consignors reference</consignor>
      <consignee>Consignees reference</consignee>
    </references>
    <messages>
      <carrier>Contact Fred before delivery</carrier>
      <consignee>Please contact me before unpacking</consignee>
    </messages>
  </consignment>
</consignments>
```

Here is a typical example of a groupage consignment for Postnord
```xml
<consignments>
  <consignment transport_agreement="1" print="false">
    <values>
	<value name="provider" value="Logistra AS" />
        <value name="provider-email" value="post@logistra.no" />
	<value name="orderno" value="123" />
    </values>
    <transfer>true</transfer>
    <product>tg_stykkgods</product>
    <parts>
      <consignee>
        <name>Juan Ponce de Leon</name>
        <postcode>1337</postcode>
        <city>Sandvika</city>
        <country>NO</country>
      </consignee>
    </parts>
    <items>
      <item type="package" amount="1" weight="2.54" volume="3" description="Something"/>
    </items>
    <references>
      <consignor>Consignor ref</consignor>
    </references>
  </consignment>
</consignments>
```

Typical example of a MyPack consignment for PostNord with the service SMS notification added.
```xml
<consignments>
  <consignment transport_agreement="1" print="false">
    <values>
        <value name="provider" value="Logistra AS" />
        <value name="provider-email" value="post@logistra.no" />
        <value name="orderno" value="123" />
    </values>
    <transfer>true</transfer>
    <product>mypack</product>
    <parts>
      <consignee>
        <name>Knut Hansen</name>
        <country>NO</country>
        <postcode>6310</postcode>
        <city>Veblungsnes</city>
      </consignee>
      <service_partner>
        <number>3042033</number>
        <name>ROMSDAL BLOMSTER OG GAVER</name>
        <address1>RAUMASENTERET ØRAN</address1>
        <country>NO</country>
        <postcode>6300</postcode>
        <city>Åndalsnes</city>
      </service_partner>
    </parts>
    <items>
      <item type="package" amount="1" weight="2.54" volume="3" description="Something"/>
    </items>
    <services>
      <service id="postnord_notification_sms"></service>
    </services>
    <references>
      <consignor>Consignor ref</consignor>
    </references>
  </consignment>
</consignments>
```

## Response
The Response XML will always return complete information about a consignment. This means its up to you to decide what information to use. In this documentation we will only show and describe the elements that we think is most useful. The XML Response will return elements that may not have any meaning to your implementation.

```xml
<consignments>
    <consignment>
        <number-with-checksum>4017071219003212178</number-with-checksum>
        <!--    Consignment number. Unique number can be used as parameter for 
                Track & Trace among other. Generated by Cargonizer. 
        -->
        <bundles>
            <pieces>
                <number-with-checksum>037880053855047</number-with-checksum>
                <!-- Parcel identificator. SSCC number. Unique. Generated by Cargonizer. -->            
            
            </pieces>
            <pieces>
                <number-with-checksum>037880053855054</number-with-checksum>
            </pieces>
        </bundles>
        <values>
            <!-- The returned element as you specified it in the request XML. See description above. -->
            <value name="order" value="123"/>
            <value name="humbaba" value="enkidu"/>
        </values>
        <consignment-pdf>https://cargonizer.no/consignments/19.pdf</consignment-pdf>
        <!-- URL for address and barocde labels -->
        <waybill-pdf>https://cargonizer.logistra.no/consignments/19.pdf?type=waybill</waybill-pdf>
        <!-- URL for waybill.  -->
        <tracking-url>https://my.postnord.no/tracking/70727320392974462</tracking-url>
        <!-- URL to be used to Track & Trace the consignment  -->
        <cost-estimate>
            <!-- Freight costs comes under this element  -->
            <net>71</net>
            <!-- Net freight costs. Based on your transport_agreement -->
            <gross>53</gross>
            <!-- Gross freight costs.  -->
        
        </cost-estimate>
    </consignment>
</consignments>
```
### Using the Response Values
Some of the elements in the response XML contains an URL. Those URL’s is only accessible trough an API call. It can not be referenced directly. Here is how to download url values in the response XML:
```bash
curl -H'X-Cargonizer-Key:9d6116ba' -H'X-Cargonizer-Sender:6' -XGET https://api.cargonizer.logistra.no/consignments/26.pdf
```


====================================================================================================
PAGE 7 OF 13
TITLE: Error Handling
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Error-Handling
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Error-Handling.md
====================================================================================================

Errors can occur at different levels. Sometimes it's an authentication error, sometimes the request parameters are missing some required information and sometimes there's a bug. Interacting with an API, you're going to have to handle errors; this document outlines how Cargonizer behaves when an error occurs.

## HTTP status

The first thing to check is the HTTP status code. If it's not in the 200 range (or a 302 redirect), you've got an unexpected result to deal with. Here are some of the status codes we use:

* **200**: Your request was successful
* **201**: The resource you sent was created
* **302**: Redirect: Follow the 'Location' header to the next URL
* **400**: Validation or other "user" error (i.e. your fault). Review the error messages to see what's wrong
* **401**: Authentication error. Check that you've authenticated properly
* **402**: Authorization error. Most likely, the sender ID is missing or incorrect
* **403**: Error. Either the sender ID is missing or incorrect or you have reached the limit of your licence
* **404**: The resource you're trying to reach can not be found. This can occur if you're requesting a resource that belongs to a different sender
* **500**: An error occured. This is most likely caused by missing or invalid parameters or by sending offending characters in the xml (&,<,>,",' etc. Remember to escape them.). Sometimes it can also be caused by a bug at our side so please contact us if you can't find any reasons why this error occure.
* **502**: Temporarily unavailable. This may happen during a deployment or configuration change. Wait a few seconds and try again.

## Error messages
In addition to the status code, there will be an error message. If you've requested to be informed with XML (url ends with ".xml"), the error messages will follow a common structure:

```xml
<errors>
  <info> ... </info>
  <error>Error 1</error>
  <error>Error 2</error>
</errors>
```
That is, an `<errors>` element with one or more `<error>` elements containing an error description. In addition, an `<info>` element will be present with information to help debugging what's wrong. It can look something like this:
```xml
<errors>
  <info>
    <request-id>92bd1c0fb905197d165a515cf82f2a0f</request-id>
    <user>
      <id>5432</id>
      <username>[apiuser@company.com](mailto:apiuser@company.com)</username>
    </user>
    <managership>
      <id>4567</id>
      <sender>
        <id>7890</id>
        <name>Joe's Garage</name>
      </sender>
    </managership>
  </info>
  <error>Missing transport agreement</error>
</errors>
```

## Common errors:

`Missing transport agreement`

> You are trying to create a consignment on a transport agreement that's not active on the account of the api user.

`Product not in transport agreement`

> You are trying to create a consignment with a product identyfier that's not available in the api users account.

`Services er ugyldig`

> You are trying to add freight services that doesnt correspond with the product. Typically trying to add a service from one carrier to another.

`500 Internal Server Error`

> Check your xml. Are all your tags closed? Do you have any typos in the tags? This can also be caused by not escaping offending characters (Characters like &, <,>,",') in your data, causing a crash.

`This action requires a sender to be specified`

> Most likely you have an incorrect SenderID. You can verify your SenderID through [profile.xml](../Authentication).

`This action requires authentication`

> Most likely your API key is wrong, please verify your api key with the account owner.


====================================================================================================
PAGE 8 OF 13
TITLE: Examples
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Examples
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Examples.md
====================================================================================================

A few shipping products and services do require additional input from the user. A few of those special cases are illustrated with examples here. Please contact us if you are going to use such a service that is not listed here. Most other services has no other attributes.They are only specified with an id attribute on the `<service>` element like this: 

```xml
<service id="postnord_notification_sms" />
```


<details>
<summary><em>Bring - Betale ved utlevering (COD)</em></summary>

```xml
<service id="bring2_cash_on_delivery">
  <amount>4571</amount>
  <account>34541024533</account>
  <currency>NOK</currency>
  <reference>1000076353545222</reference> <-- Optional -->
</service>
```
</details>

<details>
<summary><em>Bring - Valgfritt Hentested</em></summary>

### Domestic Shipments
```xml

<service id="bring2_choice_of_pickup_point" />
<parts>
    ...
    ...
    <service_partner>
        <number>2645534</number>
        <name>Extra Brandbu</name>
        <address1>Torgvegen 3</address1>
        <postcode>2760</postcode>
        <city>BRANDBU</city>
        <country>NO</country>
    </service_partner>
    ...
    ...
</parts>
```

### Abroad Shipments
**Note**: When shipping abroad you must include a `<customer-number>` element. 
This element is also returned from the [Service Point API](Service-Points).
```xml

<service id="bring_valgfritt_postkontor" />
<parts>
    ...
    ...
    <service-partner>
        <number>599358</number>
        <customer-number>c===0010</customer-number>
        <name>OKQ8 BORGHOLM</name>
        <address1>STORGATAN 77</address1>
        <address2/>
        <postcode>38734</postcode>
        <city>Borgholm</city>
        <country>SE</country>
    </service-partner>
    ....
    ....
</parts>

```
</details>

<details>
<summary><em>Bring - Leveringsdato</em></summary>

```xml
<service id="bring2_delivery_date">
  <date>2023-08-27</date> <!-- required -->
</service>
```
</details>

<details>
<summary><em>Bring - Tilleggsforsikring</em></summary>

```xml
<service id="bring2_optional_insurance" />

```
In addition you must add insurance amount and item description for each item/parcel:
```xml
<items>
    <item type="package" amount="1" weight="1" description="Handbags" insurance_amount="890" />
</items>

```

</details>

<details>
<summary><em>Bring - Pallehåndtering</em></summary>

```xml
<service id="bring2_pallet_handling">
  <num_pallets>2</num_pallets> <!-- required -->
</service>
```
</details>

<details>
<summary><em>Bring - Pickup Order</em></summary>

```xml
<service id="bring2_pickup_order">
  <date>2023-08-15</date>
  <time>13:00</time>
  <message>Ring Paul på lageret</message>
</service>
```
</details>

<details>
<summary><em>Bring - Tidsvindu for henting og levering</em></summary>

```xml
<service id="bring2_time_window_delivery">
  <earliest>2023-08-15T13:00:00</earliest>
  <latest>2023-08-15T16:00:00</latest> <!-- required -->
</service>

<service id="bring2_time_window_pickup">
  <earliest>2023-08-15T13:00:00</earliest> <!-- required -->
  <latest>2023-08-15T16:00:00</latest>
</service>
```
</details>

<details>
<summary><em>DHL Express - Electronic Invoice</em></summary>

All possible values shown here. Please contact DHL Express for further details

```xml
<service id="dhl_express_electronic_invoice_edi">
   <consignor_eori_no></consignor_eori_no>
   <consignor_eori_country></consignor_eori_country>
   <consignor_tax_id_vat_no></consignor_tax_id_vat_no>
   <consignor_tax_id_vat_country></consignor_tax_id_vat_country>
   <consignor_ioss_osr_lvg_voec_no>string</consignor_ioss_osr_lvg_voec_no>
   <consignor_ioss_osr_lvg_voec_country>string</consignor_ioss_osr_lvg_voec_country>
   <consignee_tax_id_vat_no></consignee_tax_id_vat_no>
   <consignee_tax_id_vat_country></consignee_tax_id_vat_country>
   <consignee_ioss_osr_lvg_voec_no>string</consignee_ioss_osr_lvg_voec_no>
   <consignee_ioss_osr_lvg_voec_country>string</consignee_ioss_osr_lvg_voec_country>
   <consignee_eori_no>string</consignee_eori_no>
   <consignee_eori_country>string</consignee_eori_country>
   <billed_to_name>Mr Joe</billed_to_name>
   <billed_to_address1>Street 3</billed_to_address1>
   <billed_to_address2></billed_to_address2>
   <billed_to_country>IT</billed_to_country>
   <billed_to_postcode>232522</billed_to_postcode>
   <billed_to_city>Roma</billed_to_city>
   <billed_to_phone></billed_to_phone>
   <billed_to_tax_id_vat_no></billed_to_tax_id_vat_no>
   <billed_to_tax_id_vat_country></billed_to_tax_id_vat_country>
   <billed_to_contact_name>string</billed_to_contact_name>
   <billed_to_email>string</billed_to_email>
   <billed_to_eori_no>string</billed_to_eori_no>
   <billed_to_eori_country>string</billed_to_eori_country>
   <billed_to_ioss_osr_lvg_voec_no>string</billed_to_ioss_osr_lvg_voec_no>
   <billed_to_ioss_osr_lvg_voec_country>string</billed_to_ioss_osr_lvg_voec_country>
   <bank_inn></bank_inn>
   <bank_ogrn></bank_ogrn>
   <bank_kpp></bank_kpp>
   <bank_okpo></bank_okpo>
   <bank_settlement_account_usd_eur></bank_settlement_account_usd_eur>
   <bank_settlement_account_rur></bank_settlement_account_rur>
   <bank_name></bank_name>
   <invoice_number></invoice_number>  <!-- REQUIRED -->
   <exporter_id></exporter_id>
   <exporter_code></exporter_code>
   <exporter_name>string</exporter_name>
   <exporter_address1>string</exporter_address1>
   <exporter_address2>string</exporter_address2>
   <exporter_country>string</exporter_country>
   <exporter_postcode>string</exporter_postcode>
   <exporter_city>string</exporter_city>
   <exporter_phone>string</exporter_phone>
   <exporter_contact_name>string</exporter_contact_name>
   <exporter_email>string</exporter_email>
   <exporter_tax_id_vat_no>string</exporter_tax_id_vat_no>
   <exporter_tax_id_vat_country>string</exporter_tax_id_vat_country>
   <exporter_eori_no>string</exporter_eori_no>
   <exporter_eori_country>string</exporter_eori_country>
   <exporter_ioss_osr_lvg_voec_no>string</exporter_ioss_osr_lvg_voec_no>
   <exporter_ioss_osr_lvg_voec_country>string</exporter_ioss_osr_lvg_voec_country>
   <other_remarks></other_remarks>
   <reason_for_export>ENUM</reason_for_export> <!-- REQUIRED -->
   <type_of_export>ENUM</type_of_export> <!-- REQUIRED -->
   <other_charges></other_charges>
   <freight_cost></freight_cost>
   <insurance_cost></insurance_cost>
   <total_goods_value>decimal</total_goods_value> <!-- REQUIRED -->
   <currency_code></currency_code> <!-- REQUIRED -->
   <terms_of_payment></terms_of_payment> <!-- REQUIRED -->
   <payer_of_gst_vat></payer_of_gst_vat>
   <duty_taxes_acct>Receiver Will Pay</duty_taxes_acct>
   <duty_tax_billing_service></duty_tax_billing_service>
   <requiere_pedimento></requiere_pedimento>
   <ultimate_consignee></ultimate_consignee>
   <exemption_citation></exemption_citation>
   <type_of_invoice>ENUM</type_of_invoice> <!-- choose between proforma or commercial -->
   <customs_clearance_instructions>string</customs_clearance_instructions>
   <place_of_incoterms>string</place_of_incoterms>
   <signature>string</signature>
   <signature_name>string</signature_name>
   <invoice_date_time>DateTime: Eks: 2023-02-21T12:05</invoice_date_time> <!-- REQUIRED -->
   <export_or_import>ENUM</export_or_import> <!-- REQUIRED -->
   <commercial_or_personal>ENUM</commercial_or_personal> <!-- REQUIRED -->
   <origin_declaration_text_with_or_without_exporter_id>string</origin_declaration_text_with_or_without_exporter_id>
   <data> <!-- REQUIRED -->
        {
            "items": [
              {
                "description": "Batteries",
                "commodity_code": "BT123",
                "quantity": 300,
                "unit_value": 0.5,
                "sub_total_value": 150,
                "net_weight": 10,
                "gross_weight": 15,
                "country_of_origin": "DK"
                "part_number": "string",
                "eccn_number": "string",
                "sku_number": "string",
                "gst_paid": boolean,
                "original_export_date": "string: eks: 2022-01-01",
                "original_outbound_carrier": "string",
                "original_export_tracking_id": "string",
                "dds": "12345",
                "taric": "64324",
              },
              {
                "description": "Flux Capacitor",
                "commodity_code": "FX3000",
                "quantity": 1,
                "unit_value": 10,
                "sub_total_value": 10,
                "net_weight": 500,
                "gross_weight": 500,
                "country_of_origin": "DE"
                "part_number": "string",
                "eccn_number": "string",
                "sku_number": "string",
                "gst_paid": boolean,
                "original_export_date": "string: eks: 2022-01-01",
                "original_outbound_carrier": "string",
                "original_export_tracking_id": "string",
                "dds": "757534",
                "taric": "2323",

              },
              {
                "description": "Units",
                "commodity_code": "XX99",
                "quantity": 45,
                "unit_value": 1.99,
                "sub_total_value": 89.55,
                "net_weight": 2,
                "gross_weight": 3.5,
                "country_of_origin": "FI"
                "part_number": "string",
                "eccn_number": "string",
                "sku_number": "string",
                "gst_paid": boolean,
                "original_export_date": "string: eks: 2022-01-01",
                "original_outbound_carrier": "string",
                "original_export_tracking_id": "string",
                "dds": "5555",
                "taric": "33333",
              }
            ]
          }
   </data>
</service> 
```

Here is the list of `ENUM` values for those fields marked as ENUM above:

`type_of_export`: permanent, temporary, return, repair

`reason_for_export`: P, T, D, M, I, C, E, S, G, U, W, D, F, R

**P** PERMANENT

**R** RETURN FOR REPAIR T TEMPORARY

**M** USED EXIBITION GOODS TO ORIGIN

**I** INTERCOMPANY_USE

**C** COMMERCIAL PURPOSE/ SALE

**E** PERSONAL BELONGINGS/PERSONAL USE S SAMPLE G GIFT

**U** RETURN TO ORIGIN

**W** WARRANTY REPLACEMENT D DIPLOMATIC GOODS F DEFENCE MATERIAL

`commercial_or_personal`: commercial, personal 

`export_or_import`: import, export 

`type_of_invoice`: proforma, commercial (Currently DHL Express only supports 'commercial')

`data`: These keys in items are required
```json
{
    "items": [
        {
            "commodity_code": "FX3000",
            "country_of_origin": "CN",
            "description": "Batteries",
            "net_weight": 10,
            "quantity": 300,
            "sub_total_value": 150,
            "unit_value": 0.5
        }
    ]
}
```

Example With **Minimum Required** Values

```xml
<service id="dhl_express_electronic_invoice_edi">
   <reason_for_export>R</reason_for_export>
   <type_of_export>repair</type_of_export>
   <type_of_invoice>commercial</type_of_invoice>
   <invoice_number>74635533</invoice_number>
   <currency_code>EUR</currency_code>
   <export_or_import>export</export_or_import>
   <commercial_or_personal>personal</commercial_or_personal>
   <invoice_date_time>2023-02-21T12:05</invoice_date_time>
   <total_goods_value>342.50</total_goods_value>
   <terms_of_payment>DDP</terms_of_payment>
   <data>
          {
            "items": [
              {
                "quantity": 300,
                "unit_value": 0.5,
                "sub_total_value": 150,
                "net_weight": 10,
                "country_of_origin": "CN",
                "description": "Batteries",
                "commodity_code": "FX3000"
              }
            ]
          }
   </data>
</service>
```

Example of Return Shipment (import)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<consignments>
    <consignment print="false" transport_agreement="27317">
        <product>dhl_express_worldwide_nondoc</product>
        <parts>
            <consignee>
                <name>Hieu Lie</name>
                <address1>Sætreberget 22</address1>
                <country>SE</country>
                <postcode>12346</postcode>
                <city>Stockholm</city>
            </consignee>
        </parts>
        <items>
            <item amount="1" description="test1" type="package"  weight="0.5"/>
        </items>
        <tod city="Stockholm" code="DAP" country="SE" postcode="12346"/>
        <services>
            <service id="dhl_express_return"/>
            <service id="dhl_express_customs_value">
                <amount>6300.00</amount>
                <currency>nok</currency>
            </service>
            <service id="dhl_express_paperless_trade"/>
            <service id="dhl_express_electronic_invoice_edi">
                <reason_for_export>U</reason_for_export>
                <type_of_export>return</type_of_export>
                <type_of_invoice>commercial</type_of_invoice>
                <terms_of_payment>DAP</terms_of_payment>
                <consignor_eori_no>9585</consignor_eori_no>
                <consignor_eori_country>DK</consignor_eori_country>
                <consignor_tax_id_vat_no>34443</consignor_tax_id_vat_no>
                <consignor_tax_id_vat_country>DK</consignor_tax_id_vat_country>
                <consignor_ioss_osr_lvg_voec_no>4344444</consignor_ioss_osr_lvg_voec_no>
                <consignor_ioss_osr_lvg_voec_country>DK</consignor_ioss_osr_lvg_voec_country>
                <consignee_eori_no>77777</consignee_eori_no>
                <consignee_eori_country>DK</consignee_eori_country>
                <consignee_tax_id_vat_no>3434</consignee_tax_id_vat_no>
                <consignee_tax_id_vat_country>DK</consignee_tax_id_vat_country>
                <billed_to_name>Fredriksons Fabrik</billed_to_name>
                <billed_to_address1>Gatan 23</billed_to_address1>
                <billed_to_address2>Utanfør</billed_to_address2>
                <billed_to_country>SE</billed_to_country>
                <billed_to_postcode>025652</billed_to_postcode>
                <billed_to_city>STOCHOLM</billed_to_city>
                <billed_to_phone>004658585658</billed_to_phone>
                <billed_to_tax_id_vat_no>44</billed_to_tax_id_vat_no>
                <billed_to_tax_id_vat_country>SE</billed_to_tax_id_vat_country>
                <billed_to_contact_name>Fredirk</billed_to_contact_name>
                <billed_to_email>billed@billted.to</billed_to_email>
                <billed_to_eori_no>4454</billed_to_eori_no>
                <billed_to_eori_country>FI</billed_to_eori_country>
                <billed_to_ioss_osr_lvg_voec_no>78787</billed_to_ioss_osr_lvg_voec_no>
                <billed_to_ioss_osr_lvg_voec_country>FI</billed_to_ioss_osr_lvg_voec_country>
                <invoice_number>888888</invoice_number>
                <exporter_id>99</exporter_id>
                <exporter_code>FF99FF</exporter_code>
                <exporter_name>Peder</exporter_name>
                <exporter_address1>Pedersiten 3</exporter_address1>
                <exporter_address2></exporter_address2>
                <exporter_country>SE</exporter_country>
                <exporter_postcode>87876</exporter_postcode>
                <exporter_city>sødertelje</exporter_city>
                <exporter_phone>1111111</exporter_phone>
                <exporter_contact_name>Per</exporter_contact_name>
                <exporter_email>per@mail.pp</exporter_email>
                <exporter_tax_id_vat_no>8988</exporter_tax_id_vat_no>
                <exporter_tax_id_vat_country>SE</exporter_tax_id_vat_country>
                <exporter_eori_no>55555</exporter_eori_no>
                <exporter_eori_country>SE</exporter_eori_country>
                <exporter_ioss_osr_lvg_voec_no>121212</exporter_ioss_osr_lvg_voec_no>
                <exporter_ioss_osr_lvg_voec_country>SE</exporter_ioss_osr_lvg_voec_country>
                <other_remarks>Intet at meddela</other_remarks>
                <other_charges>5050</other_charges>
                <freight_cost>900</freight_cost>
                <insurance_cost>100</insurance_cost>
                <currency_code>SEK</currency_code>
                <total_goods_value>500.00</total_goods_value>
                <place_of_incoterms>KØBENHAVN</place_of_incoterms>
                <invoice_date_time>2023-02-21T12:05</invoice_date_time>
                <export_or_import>import</export_or_import>
                <commercial_or_personal>commercial</commercial_or_personal>
                <origin_eeclaration_text_with_or_without_exporter_id>en lang tekst</origin_eeclaration_text_with_or_without_exporter_id>
                <data>
                 {
                     "items": [
                       {
                         "description": "Batteries",
                         "commodity_code": "BT123",
                         "quantity": 300,
                         "unit_value": 0.5,
                         "sub_total_value": 150,
                         "net_weight": 10,
                         "gross_weight": 15,
                         "country_of_origin": "DK",
                         "part_number": "NN565",
                         "eccn_number": "32323",
                         "sku_number": "sKU45",
                         "gst_paid": false,
                         "original_export_date": "2022-01-01",
                         "original_outbound_carrier": "dhl",
                         "original_export_tracking_id": "898989898"
                       }
                     ]
                   }
            </data>
            </service>
        </services>
        <references>
            <consignor>10007 / 996963</consignor>
        </references>
    </consignment>
</consignments>

```

</details>
<details>
<summary><em>DHL Express - Tollverdi</em></summary>

```xml
<service id="dhl_express_customs_value">
   <amount>670.00</amount> <!-- REQUIRED -->
   <currency>EUR</currency> <!-- REQUIRED -->
   <paid_by_custno>3252252</paid_by_custno>
</service>
```

</details>

<details>
<summary><em>DHL Express - Duties & Taxes Paid (DD)</em></summary>

```xml
<service id="dhl_express_dtp">
   <paid_by_custno>924232332</paid_by_custno> <!-- Optional -->
</service>
```
</details>

<details>
<summary><em>DHL Express - Forsikring</em></summary>

```xml
<service id="dhl_express_insurance">
   <amount>670.00</amount> <!-- REQUIRED -->
   <currency>eur</currency> 
</service>
```
</details>


<details>
<summary><em>DHL Express - Booking / Pickup</em></summary>

The aditional information is not required, but will help in terms of making a successful booking/pickup. DHL Express does not notify the sender if any requirements in terms of time, as described below, are not fulfillled. The booking will simply fail.

```xml
<service id="dhl_express_pickup">
   <pickup_time>2022-09-22T13:00</pickup_time> <!-- Note! It must be at least one hour until pickup is booked! -->
   <pickup_location>i.e. Oslo City, second floor.</pickup_location>
   <pickup_location_close_time>17:00</pickup_location_close_time> <!-- Note! Booking must be at least one hour before closing time! -->
   <pickup_instruction>i.e. Ask for Christopher.</pickup_instruction>
</service>
```
</details>


<details>
<summary><em>Postnord - Groupage Return</em></summary>


These parties are mandatory

```xml
<consignment>
  ...
  <product>stykkgods_innland_booking_me</product>
  ...
  <parts>
    <consignee freight_payer="true">
      <customer-number>3252512</customer-number>
      <name>Mitt Firma AS</name>
      <country>NO</country>
      <postcode>6412</postcode>
      <city>Molde</city>
    </consignee>

    <pickup_address>
      <name>Lagerplassen</name>
      <address1>Lagerveien 34</address1>
      <country>NO</country>
      <postcode>0068</postcode>
      <city>Oslo</city>
    </pickup_address>
  </parts>
</consignment>

</parts>

```
</details>

<details>
<summary><em>Postnord - Groupage Pickup-Delivery</em></summary>


These parties are mandatory

```xml
<consignment>
  ...
  <product>stykkgods_innland_booking_other</product>
  ...
  <parts>
    <consignee>
      <name>Leveringsfirma AS</name>
      <country>NO</country>
      <postcode>1338</postcode>
      <city>Sandvika</city>
    </consignee>

    <pickup_address>
      <name>Lagerplassen</name>
      <address1>Lagerveien 34</address1>
      <country>NO</country>
      <postcode>1412</postcode>
      <city>Sofiemyr</city>
    </pickup_address>

    <freight_payer_address>
      <customer-number>3252512</customer-number>
      <name>Mitt Firma AS</name>
      <address1>Betalerveien 34</address1>
      <country>NO</country>
      <postcode>0068</postcode>
      <city>Oslo</city>
    </freight_payer_address>

  </parts>
</consignment>
```
</details>

<details>
<summary><em>Postnord - Pallet</em></summary>

Postnord requries that you tell what type of Pallet you ship. You need to use one of these:

```xml
<consignment>
  ...
  <product>tg_pall</product>
  ...
  <items>
    <item type="palette" .....></item>
    <item type="1_4_palette" ....></item>
    <item type="1_2_palette" ....></item>
    <item type="1_1_palette" ....></item>
    <item type="x_palette" ....></item>
  </items>
  ...
</consignment>
```
</details>


<details>
<summary><em>Postnord - Etterkrav </em></summary>

```xml
<service id="tg_etterkrav">
  <amount>4571</amount>
  <currency>NOK</currency>
  <kid>1000076353545222</kid> <-- optional -->
</service>
```
</details>

<details>
<summary><em>Postnord - COD </em></summary>
Only to be used for some export products

```xml
<service id="cod">
   <amount>4571</amount>
   <currency>EUR</currency>
   <transaction_id>1000076353545222</transaction_id> <-- optional -->
   <account_number>40542312211</account_number>
   <account_type></account_type>
   <bic>DABAIE2D</bic>
   <iban>987654321</iban>
</service>
```

</details>

<details>
<summary><em>Postnord - Forsikring </em></summary>

```xml
<service id="tg_insurance">
   <amount>4571</amount>
   <currency>nok</currency>
</service>
```

</details>

<details>
<summary><em>Schenker Domestic- Enhetskodet gods</em></summary>

```xml
<service id="schenker_domestic_unit_code">
   <unit_code>51</unit_code>
</service>
```
Valid `unit_code` values are:
* 51
* 52
* 53
* 54
* 61
* 71

You need an agreement with Schenker to use this service and they inform you what code to use.

</details>

<details>
<summary><em>Schenker Domestic - Fix Day</em></summary>

```xml
<service id="schenker_domestic_fix_day">
   <date>2023-09-30</date> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Schenker Domestic - Fix Day10</em></summary>

```xml
<service id="schenker_domestic_fix_day_10">
   <date>2023-09-30</date> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Schenker Domestic - Fix Day13</em></summary>

```xml
<service id="schenker_domestic_fix_day_13">
   <date>2023-09-30</date> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Schenker Domestic - Fix day delivery</em></summary>

```xml
<service id="schenker_domestic_fix_day_delivery">
   <date>2023-09-30</date> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Schenker Domestic - Fix day pick-up</em></summary>

```xml
<service id="schenker_domestic_fix_day_pickup">
   <date>2023-09-30</date> <!-- required -->
</service>
```

</details>



<details>
<summary><em>Schenker Domestic - Plus pick-up</em></summary>

```xml
<service id="schenker_domestic_plus_pickup">
   <date>2023-09-30</date> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Schenker Domestic - Return shipment</em></summary>

```xml
<service id="schenker_domestic_return_private">
   <date>2023-09-30</date> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Schenker Utland- Cash On Delivery(COD)</em></summary>

```xml
<service id="schenker_cod">
   <amount>2023-09-30</amount> <!-- required -->
   <currency>
       <!-- Possible values -->
       <!-- eur -->
       <!-- dkk  -->
       <!-- sek-->
       <!-- usd -->
   </currency>
   <giro_number>87888777666</giro_number>
   <payee>Name of Payee</payee> <!-- required -->
   <reference_number>BG456456</reference_number> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Schenker Utland- Fix day</em></summary>


```xml
<service id="schenker_fix_day"> <!-- Fix Day -->
   <delivery_time>2023-09-30</delivery_time> <!-- required -->
</service>

<service id="schenker_fix_day_10"> <!-- Fix Day 10 -->
   <delivery_time>2023-09-30</delivery_time> <!-- required -->
</service>

<service id="schenker_fix_day_13"> <!-- Fix Day 13 -->
   <delivery_time>2023-09-30</delivery_time> <!-- required -->
</service>
```

</details>


<details>
<summary><em>Schenker Utland - Insurance</em></summary>

```xml
<service id="schenker_insurance">
   <amount>250.50</amount> <!-- required -->
   <currency>usd</currency> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Postnord Sverige - Time Agreed Delivery</em></summary>

```xml
<service id="postnord_leveransanvisning">
   <leverans_nr>71</leverans_nr>
</service>
```

Valid `leverans_nr` are:
* 71
* 72
* 73
* 74
* 53
* 89
</details>

<details>
<summary><em>DSV - Temperaturgods</em></summary>

```xml
<service id="dsv_temp_goods">
   <temp_max>10</temp_max> <!-- required -->
   <temp_min>1</temp_min> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Brevpost - Digitalt frimerke</em></summary>

```xml
<service id="digital_stamp">
   <stamp_code>857463522</stamp_code> <!-- required -->
</service>
```

</details>

<details>
<summary><em>Budbilene - Leveringstidspunkt</em></summary>

```xml
<service id="budbilene_delivery_time">
   <requested_delivery_time>2023-11-02T12:00:00:00</requested_delivery_time>
   <requested_pickup_time>2023-11-01T09:30:00</requested_pickup_time> 
</service>
```

</details>


<details>
<summary><em>Hurtig-gutta Transport - Leveringstidspunkt</em></summary>

```xml
<service id="delivery_time">
   <requested_delivery_time>2023-11-02T12:00:00:00</requested_delivery_time>
   <requested_pickup_time>2023-11-01T09:30:00</requested_pickup_time> 
</service>
```

</details>


====================================================================================================
PAGE 9 OF 13
TITLE: Printing
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Printing
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Printing.md
====================================================================================================

## Listing printers

|||
|---|---|
|Endpoint|/printers|
|[Formats](Sending-Requests#formats)|XML, JSON|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|No|
|Requires [DirectPrint](https://www.logistra.no/kunderessurser/directprint) physically connected to thermal printer(s)|No|

### Getting a list of all printers

<sub>_cURL_</sub>
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' 'https://api.cargonizer.no/printers.json'
```

<sub>_HTTP_</sub>
```
GET /printers.json HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'GET';
http.url = 'https://api.cargonizer.no/printers.json';
http.headers.add('X-Cargonizer-Key', '12345');
response = http.execute();
```

## Printing labels

|||
|---|---|
|Endpoint|/consignments/label_direct|
|[Formats](Sending-Requests#formats)|XML, JSON|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|Yes|
|Requires [DirectPrint](https://www.logistra.no/kunderessurser/directprint) physically connected to thermal printer(s)|Yes|

This endpoint will create a job to physically print labels on the appropriate DirectPrint connected printer.

**Note:** If you plan to print in bulk, you must make one single call and enter the identifiers as an array. Then you will have a guarantee for a single print job where the labels come out in the same order. Do not print one label at a time as you may get the order wrong and labels (print jobs) may disappear.

<sub>_cURL_</sub>
```bash
curl -g -XPOST -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' 'https://api.cargonizer.no/consignments/label_direct?printer_id=123&consignment_ids[]=1&consignment_ids[]=2&piece_ids[]=3&piece_ids[]=4'
```

<sub>_HTTP_</sub>
```
POST /consignments/label_direct?printer_id=123&consignment_ids[]=1&consignment_ids[]=2&piece_ids[]=3&piece_ids[]=4 HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345 X-Cargonizer-Sender: 678
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'POST';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('X-Cargonizer-Sender: 678');
http.url = 'https://api.cargonizer.no/consignments/label_direct?printer_id=123&consignment_ids[]=1&consignment_ids[]=2&piece_ids[]=3&piece_ids[]=4';
http.queryStringParameters.set('printer_id', '123');
http.queryStringParameters.set('consignment_ids[]', '1');
http.queryStringParameters.set('consignment_ids[]', '2');
http.queryStringParameters.set('piece_ids[]', '3');
http.queryStringParameters.set('piece_ids[]', '4');
response = http.execute();
```

## Download labels as PDF
|||
|---|---|
|Endpoint|/consignments/label_pdf|
|Response [format](Sending-Requests#formats)|PDF|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|Yes|

<sub>_cURL_</sub>
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' 'https://api.cargonizer.no/consignments/label_pdf?consignment_ids[]=1&consignment_ids[]=2&piece_ids[]=3&piece_ids[]=4'
```

<sub>_HTTP_</sub>
```
GET /consignments/label_pdf?consignment_ids[]=1&consignment_ids[]=2&piece_ids[]=3&piece_ids[]=4 HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345
X-Cargonizer-Sender: 678
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'GET';
http.url = 'https://api.cargonizer.no/consignments/label_pdf?consignment_ids[]=1&consignment_ids[]=2&piece_ids[]=3&piece_ids[]=4';
http.queryStringParameters.set('consignment_ids[]', '1');
http.queryStringParameters.set('consignment_ids[]', '2');
http.queryStringParameters.set('piece_ids[]', '3');
http.queryStringParameters.set('piece_ids[]', '4');
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('X-Cargonizer-Sender', '678');
response = http.execute();
```


### Download waybill as PDF

|||
|---|---|
|Endpoint|/waybill_pdf|
|[Formats](Sending-Requests#formats)|PDF|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|Yes|

<sub>_cURL_</sub>
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' 'https://api.cargonizer.no/consignments/waybill_pdf?consignment_ids[]=1&consignment_ids[]=2'
```

<sub>_HTTP_</sub>
```
GET /consignments/waybill_pdf?consignment_ids[]=1&consignment_ids[]=2 HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345
X-Cargonizer-Sender: 678
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'GET';
http.url = 'https://api.cargonizer.no/consignments/waybill_pdf?consignment_ids[]=1&consignment_ids[]=2';
http.queryStringParameters.set('consignment_ids[]', '1');
http.queryStringParameters.set('consignment_ids[]', '2');
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('X-Cargonizer-Sender', '678');
response = http.execute();
```

### Download goods declaration as PDF

|||
|---|---|
|Endpoint|/goods_declaration_pdf|
|[Formats](Sending-Requests#formats)|PDF|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|Yes|

<sub>_cURL_</sub>
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' 'https://api.cargonizer.no/consignments/goods_declaration_pdf?consignment_ids[]=1&consignment_ids[]=2'
```

<sub>_HTTP_</sub>
```
GET /consignments/goods_declaration_pdf?consignment_ids[]=1&consignment_ids[]=2 HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345
X-Cargonizer-Sender: 678
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'GET';
http.url = 'https://api.cargonizer.no/consignments/goods_declaration_pdf?consignment_ids[]=1&consignment_ids[]=2';
http.queryStringParameters.set('consignment_ids[]', '1');
http.queryStringParameters.set('consignment_ids[]', '2');
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('X-Cargonizer-Sender', '678');
response = http.execute();
```

## Printing arbitrary PDF or ZPL/EPL

|||
|---|---|
|Endpoint|/prints|
|[Formats](Sending-Requests#formats)|JSON,PDF,ZPL,EPL|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|No|
|Requires [DirectPrint](https://www.logistra.no/kunderessurser/directprint) physically connected to thermal printer(s)|Yes|
 
### Parameters

Parameters are typically supplied using a JSON payload, or a combination of HTTP query string and body with the associated `Content-Type` header.

Required/allowed parameters visualized in a normalized format:

```
{
  print: {
    printer: {
      id: Integer
    },
    data: {
      type: String['application/pdf' | 'application/x.zpl' | 'application/x.epl']
      encoding: ?String['base64']
      content: String
    }
  }
}
```

* `print.data.type` indicates the type of the content, which is either PDF or ZPL/EPL depending on the printer.
* `print.data.encoding` is not required, but may be "base64" to indicate content is Base64 encoded.



### JSON payload

A JSON payload will typically look like this:

```json
{
  "print": {
    "printer": {"id": 12345},
    "data": {
      "type": "application/pdf",
      "encoding": "base64",
      "content": "<base64 encoded PDF goes here>"
    }
  }
}
```

`encoding` is necessarily "base64" with binary data, which can't be represented direcly in JSON:

```bash
curl \
  -XPOST \
  -H'X-Cargonizer-Key: 12345' \
  -H'Content-Type: application/json' \
  -H'Accept: application/json' \
  --data-binary '{"print": {"printer": {"id": 123}, "data": {"type": "application/x.zpl", "encoding": "base64", "content": "XlhBXkZPNTAsNjBeQTAsMjAwXkZESGVsbG9eRlNeRk81MCwzMDBeQTAsMjAwXkZEV29ybGReRlNeWFoK"}}}' \
  'https://api.cargonizer.no/prints'
```

```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.no/prints';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('Content-Type', 'application/json');
http.headers.add('Accept', 'application/json');
http.body = EncodeJSON({
  print: {
    printer: {id: 123},
    data: {
      type: "application/x.zpl",
      encoding: "base64",
      content: EncodeBase64("^XA^FO50,60^A0,200^FDHello^FS^FO50,300^A0,200^FDWorld^FS^XZ")
    }
  }
});
response = http.execute();
```

But it can be omitted if the data is a valid JSON string:

```bash
curl \
  -XPOST \
  -H'X-Cargonizer-Key: 12345' \
  -H'Content-Type: application/json' \
  -H'Accept: application/json' \
  --data-binary '{"print": {"printer": {"id": 123}, "data": {"type": "application/x.zpl", "content": "^XA^FO50,60^A0,200^FDHello^FS^FO50,300^A0,200^FDWorld^FS^XZ"}}}' \
  'https://api.cargonizer.no/prints'
```

```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.no/prints';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('Content-Type', 'application/json');
http.headers.add('Accept', 'application/json');
http.body = EncodeJSON({
  print: {
    printer: {id: 123},
    data: {
      type: "application/x.zpl",
      content: "^XA^FO50,60^A0,200^FDHello^FS^FO50,300^A0,200^FDWorld^FS^XZ"
    }
  }
});
response = http.execute();
```


### Content as HTTP body

Content to be printed can be provided directly in HTTP body by setting the Content-Type header appropriately. The value of the header is the type of content to be printed with an optional header param specifying Base64 encoding.

Raw ZPL:

```bash
curl -XPOST \
  --data-binary '^XA^FO50,60^A0,200^FDHello^FS^FO50,300^A0,200^FDWorld^FS^XZ' \
  -H'X-Cargonizer-Key: 12345' \
  -H'Content-Type: application/x.zpl' \
  -H'Accept: application/json' \
  'https://api.cargonizer.no/prints?print[printer][id]=12345'
```

```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.no/prints';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('Content-Type', 'application/x.zpl');
http.headers.add('Accept', 'application/json');
http.parameters.add('print[printer][id]', '123');
http.body = "^XA^FO50,60^A0,200^FDHello^FS^FO50,300^A0,200^FDWorld^FS^XZ";
response = http.execute();
```

Base64 encoded ZPL:

```bash
curl \
  -XPOST \
  --data-binary 'XlhBXkZPNTAsNjBeQTAsMjAwXkZESGVsbG9eRlNeRk81MCwzMDBeQTAsMjAwXkZEV29ybGReRlNeWFoK' \
  -H'X-Cargonizer-Key: 12345' \
  -H'Content-Type: application/x.zpl;encoding=base64' \
  -H'Accept: application/json' \
  'https://api.cargonizer.no/prints?print[printer][id]=12345'
```

```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.no/prints';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('Content-Type', 'application/x.zpl;encoding=base64');
http.headers.add('Accept', 'application/json');
http.parameters.add('print[printer][id]', '123');
http.body = Base64Encode("^XA^FO50,60^A0,200^FDHello^FS^FO50,300^A0,200^FDWorld^FS^XZ");
response = http.execute();
```

Base64 encoded PDF body:

```http
POST /prints
X-Cargonizer-Key: 12345
Content-Type: application/pdf;encoding=base64
Accept: application/json

JVBERi0xLjEKJcKlwrHDqwoKMSAwIG9iagogIDw8IC9UeXBlIC9DYXRhbG9nCiAgICAgL1BhZ2Vz
IDIgMCBSCiAgPj4KZW5kb2JqCgoyIDAgb2JqCiAgPDwgL1R5cGUgL1BhZ2VzCiAgICAgL0tpZHMg
WzMgMCBSXQogICAgIC9Db3VudCAxCiAgICAgL01lZGlhQm94IFswIDAgMjg5LjEzNCA1NDQuMjUy
XQogID4+CmVuZG9iagoKMyAwIG9iagogIDw8ICAvVHlwZSAvUGFnZQogICAgICAvUGFyZW50IDIg
MCBSCiAgICAgIC9SZXNvdXJjZXMKICAgICAgIDw8IC9Gb250CiAgICAgICAgICAgPDwgL0YxCiAg
ICAgICAgICAgICAgIDw8IC9UeXBlIC9Gb250CiAgICAgICAgICAgICAgICAgIC9TdWJ0eXBlIC9U
eXBlMQogICAgICAgICAgICAgICAgICAvQmFzZUZvbnQgL1RpbWVzLVJvbWFuCiAgICAgICAgICAg
ICAgID4+CiAgICAgICAgICAgPj4KICAgICAgID4+CiAgICAgIC9Db250ZW50cyA0IDAgUgogID4+
CmVuZG9iagoKNCAwIG9iagogIDw8IC9MZW5ndGggNTggPj4Kc3RyZWFtCiAgQlQKICAgIC9GMSAy
OCBUZgogICAgNTAgNTAwIFRkCiAgICAoSGVsbG8gV29ybGQpIFRqCiAgRVQKZW5kc3RyZWFtCmVu
ZG9iagoKeHJlZgowIDUKMDAwMDAwMDAwMCA2NTUzNSBmIAowMDAwMDAwMDE4IDAwMDAwIG4gCjAw
MDAwMDAwNzcgMDAwMDAgbiAKMDAwMDAwMDE3OCAwMDAwMCBuIAowMDAwMDAwNDU3IDAwMDAwIG4g
CnRyYWlsZXIKICA8PCAgL1Jvb3QgMSAwIFIKICAgICAgL1NpemUgNQogID4+CnN0YXJ0eHJlZgo1
NjUKJSVFT0YK
```

```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.no/prints';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('Content-Type', 'application/pdf;encoding=base64');
http.headers.add('Accept', 'application/json');
http.parameters.add('print[printer][id]', '123');
http.body = Base64Encode(ReadFile('filename.pdf'));
response = http.execute();
```


Raw PDF body:

```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.no/prints';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('Content-Type', 'application/pdf');
http.headers.add('Accept', 'application/json');
http.parameters.add('print[printer][id]', '123');
http.body = ReadFile('filename.pdf');
response = http.execute();
```


====================================================================================================
PAGE 10 OF 13
TITLE: Service Points
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Service-Points
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Service-Points.md
====================================================================================================

|||
|---|---|
|Endpoint|/service_partners|
|[Formats](Sending-Requests#formats)|XML, JSON|
|[Authentication](Authentication) required?|No, but *strongly recommended*|
|[Sender ID](Authentication#supplying-a-sender-id) required?|No, but *strongly recommended*|

## Query string parameters

### Main

These parameters tell us which carrier's service points to query. The `carrier` parameter is required. `product` and `transport_agreement_id` are recommended and will provide more accurate results in some cases.

* **carrier**
* product
* transport_agreement_id (requires authentication and sender)


### Address

Find service points close to this address. `country` and `postcode` are always required; the rest are recommended to provide more accurate results.

* **country**
* **postcode**
* city
* address
* name

### Custom parameters

Some carriers accept custom parameters. These are provided using the `custom` top-level parameter, and nested parameters are encoded using `custom[sub1][sub2]=value`.

In particular, some custom parameters are passed through to the carrier's API using `custom[params]`. These parameters will override any dynamically derived parameters, e.g. given `address=123 Some street`, the derived `streetNumber` API parameter "123" can be overridden using `custom[params][streetNumber]=321` (assuming that the carrier's API uses that parameter).

* custom
  * postnord/tollpost_globe
    * params
      * https://developer.postnord.com/apis/details?systemName=location-v5-servicepoints (see "Nearest Service Points > nearest/byaddress > Parameters")
  * bring/bring2
    * params
      * https://developer.bring.com/api/pickup-point/#get-pickup-points-by-address-get (see "Query parameters")
  * helthjem
    * params
      * https://jira-di.atlassian.net/wiki/spaces/DIPUB/pages/1413251073/Parcel+Nearby+Servicepoint+API (see "Request body")
      * https://developer.helthjem.no/parcel-apis/nearby-service-points-api


## Examples

The API does not require authentication, but it is strongly recommended to both authenticate and provide a Sender ID for more functionality and accuracy.

### Bring - Pakke til hentested

```
GET https://api.cargonizer.no/service_partners.xml?carrier=bring2&product=bring2_parcel_pickup_point&country=NO&postcode=1337&address=Kadettangen 14
```

### Postnord - MyPack Box

```
GET https://api.cargonizer.no/service_partners.xml?carrier=tollpost_globe&product=postnord_mypack_small&country=NO&postcode=1337&address=Kadettangen 14
```

### Helthjem - Hentepakke
**Please note!** This carrier requires Transport Agreement ID. Use of Transport Agreement ID requires [Authentication](authentication).
<details>
<summary><em>Authentication required</em></summary>

Using `transport_agreement_id` requires authentication and a Sender ID.

```http
GET /service_partners.json?transport_agreement_id=000&product=helthjem_hentepakke&country=NO&postcode=1337&address=Kadettangen 14
X-Cargonizer-Key: 1234567890
X-Cargonizer-Sender: 1234
```
</details>

```
GET https://api.cargonizer.no/service_partners.xml?transport_agreement_id=123&country=NO&product=helthjem_hentepakke&carrier=helthjem&postcode=1337&address=Kadettangen 14
```


### With product and TA

<details>
<summary><em>Authentication required</em></summary>

Using `transport_agreement_id` requires authentication and a Sender ID.

```http
GET /service_partners.json?transport_agreement_id=000&product=bring2_parcel_pickup_point&country=NO&postcode=1337&address=Kadettangen 14
X-Cargonizer-Key: 1234567890
X-Cargonizer-Sender: 1234
```
</details>

```
GET https://api.cargonizer.no/service_partners.xml?carrier=bring2&product=bring2_parcel_pickup_point&transport_agreement_id=000&country=NO&postcode=1337&address=Kadettangen 14
```

The `carrier` parameter can be omitted when `transport_agreement_id` is given, as the carrier can be derived from it.

```
GET https://api.cargonizer.no/service_partners.xml?transport_agreement_id=000&product=bring2_parcel_pickup_point&country=NO&postcode=1337&address=Kadettangen 14
```


### Using custom parameters

#### Bring `pickupPointType`

```
GET https://api.cargonizer.no/service_partners.xml?transport_agreement_id=000&product=bring2_parcel_pickup_point&country=NO&postcode=1337&address=Kadettangen 14&custom[params][pickupPointType]=locker
```

#### Postnord `typeId`

```
GET https://api.cargonizer.no/service_partners.xml?transport_agreement_id=000&product=postnord_mypack&country=NO&postcode=1337&address=Kadettangen 14&custom[params][typeId]=2
```

#### Helthjem `transportSolutionId` and `shopId`

```
GET https://api.cargonizer.no/service_partners.xml?carrier=helthjem&product=helthjem_mypack&country=NO&postcode=1337&address=Kadettangen 14&custom[params][transportSolutionId]=3&custom[params][shopId]=1234
```


### JSON parameters

You may send a POST request to the endpoint with a `Content-Type: application/json` header and the parameters encoded in a JSON body.

```bash
curl \
-XPOST \
-H'Content-Type: application/json' \
https://api.cargonizer.no/service_partners.json \
-d @- <<'EOF'
{
  "carrier": "bring",
  "country": "NO",
  "postcode": "1337",
  "address": "Kadettangen 14",
  "custom": {
    "params": {
      "pickupPointType": "manned"
    }
  }
}
EOF
```


====================================================================================================
PAGE 11 OF 13
TITLE: Shipping Cost Estimation
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Shipping-Cost-Estimation
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Shipping-Cost-Estimation.md
====================================================================================================

|||
|---|---|
|Endpoint|/consignment_costs|
|[Formats](Sending-Requests#formats)|XML|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|Yes|

## Shipping Cost Estimation
To be used when you want to estimate the cost of a shipment/consignment. The more accurate the consignment data, the more accurate the price will be. The XML body of the request is 100% identical to the consignments.xml [request body](Consignments).


## Usage
Requesting a cost estimation for a consignment.

<sub>_cURL_</sub>
```bash
curl -g -XPOST -d@consignment.xml -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' 'https://api.cargonizer.no/consignment_costs.xml'
```

<sub>_HTTP_</sub>
```
POST /consignment_costs.xml HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345
X-Cargonizer-Sender: 678
<contents of "consignment.xml">
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.no/consignment_costs.xml';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('X-Cargonizer-Sender', '678');
http.body = new File('consignment.xml').read();
response = http.execute();
```

_The contents of consignment_costs.xml are the same as those for [creating a consignment](Consignments)._

## Examples
### A Minimal consignment_costs.xml
```xml
<consignments>
  <consignment transport_agreement="12345">
    <product>bring2_business_parcel</product>
    <parts>
      <consignee>
        <name>Juan Ponce De Leon</name>
        <country>NO</country>
        <postcode>1337</postcode>
      </consignee>
    </parts>
    <items>
      <item type="package" amount="1" weight="12" volume="27"/>
    </items>
  </consignment>
</consignments>
```
**Note**: Weight in kg. Volume in dm3.

## Response
### Description
Gross amount is the freight price without your transport agreement and net amount is the freight price calculated by using your transport agreement.

| Element  | Description |
| ------------- | ------------- |
| gross-amount type=”float  | Gross price  |
| net-amount type=”float”  | Net price  |


### Example Of Response
```xml
<?xml version="1.0" encoding="UTF-8"?>
<consignment-cost>
    <estimated-cost type="float">96.25</estimated-cost>
    <gross-amount type="float">96.25</gross-amount>
    <net-amount type="float">55.30</net-amount>
</consignment-cost>
```


====================================================================================================
PAGE 12 OF 13
TITLE: Transfers
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Transfers
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Transfers.md
====================================================================================================

## Sending Consignments to Carrier

|||
|---|---|
|Endpoint|/consignments/transfer|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|Yes|

Creating a [Consignment](Consignment) will not transfer information to the Carrier by default. At some point it must be transferred. You have two options. Set attribute transfer="true" in your consignments.xml or use this resource.

Setting transfer="true" in consignments.xml means "Transfer this consignment to the carrier immediately". That also means you have no options to change the Consignment information in cargonizer.no after creation.

Setting transfer="false" in consignments.xml and use this transfer resource afterwards, makes you in full control of when to ship to Carrier. It also lets your customer do whatever changes they need with the Consignment between creation and shipping.

## Usage
We **strongly** recommend you to supply many consignment id's in each call/transfer instead of one transfer per consignment. 

<sub>_cURL_</sub>
```bash
curl -g -XPOST -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' \
'https://api.cargonizer.logistra.no/consignments/transfer?consignment_ids[]=5077&consignment_ids[]=590742'
```

<sub>_HTTP_</sub>
```
POST /consignments/transfer?consignment_ids[]=50690777&consignment_ids[]=50690742 HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345 X-Cargonizer-Sender: 678
```

<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'POST';
http.url = 'https://api.cargonizer.logistra.no/consignments/transfer;
http.queryStringParameters.set('consignment_ids[]', '134');
http.queryStringParameters.set('consignment_ids[]', '23');
http.headers.add('X-Cargonizer-Key', '12345');http.headers.add('X-Cargonizer-Sender: 678');response = http.execute();
```


====================================================================================================
PAGE 13 OF 13
TITLE: Transport Agreements
WIKI URL: https://github.com/logistra/cargonizer-documentation/wiki/Transport-Agreements
RAW URL: https://raw.githubusercontent.com/wiki/logistra/cargonizer-documentation/Transport-Agreements.md
====================================================================================================

|||
|---|---|
|Endpoint|/transport_agreements|
|[Formats](Sending-Requests#formats)|XML, JSON|
|[Authentication](Authentication) required?|Yes|
|[Sender ID](Authentication#supplying-a-sender-id) required?|Yes|

## Transport Agreements
A transport agreement represents an agreement between you and a carrier to transport your goods. To create a consignment you must supply the `ID` of a valid transport agreement for the carrier, `product` and `services` you want to use. This resource will also give you the rules and limitations of the products and services the carrier provide. Only transport agreements, carriers, products and services that is activated on your Cargonizer account will be returned in the response.

## Usage
Getting a list of transport agreements

<sub>_cURL_</sub>
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' 'https://api.cargonizer.no/transport_agreements.xml'
```
<sub>_HTTP_</sub>
```
GET /transport_agreements.xml HTTP/1.1
Host: cargonizer.no
X-Cargonizer-Key: 12345
X-Cargonizer-Sender: 678
```
<sub>_Pseudocode_</sub>
```
http = new HTTPRequest();
http.method = 'GET';
http.url = 'https://api.cargonizer.no/transport_agreements.xml';
http.headers.add('X-Cargonizer-Key', '12345');
http.headers.add('X-Cargonizer-Sender', '678');
response = http.execute();
```
## Response
```xml
<transport-agreements>
  <transport-agreement>
    <id>1</id>
    <description>Standard</description>
    <number>12345</number>
    <carrier>
      <identifier>bring</identifier>
      <name>Bring</name>
    </carrier>
    <products>
      <product>
        <identifier>bring_bedriftspakke</identifier>
        <name>Bedriftspakke</name>
        <services>
          <service>
            <identifier>bring_oppkrav</identifier>
            <name>Postoppkrav</name>
            <attributes>
              <attribute>
                <identifier>amount</identifier>
                <type>float</type>
                <required>true</required>
                <min>50</min>
                <!-- Minimum value for this attribute -->
                <max>5000</max>
                <!-- Maximum value for this attribute -->
              </attribute>
              <attribute>
                <identifier>currency</identifier>
                <type>string</type>
                <required>true</required>
                <!-- Allowed values for this attribute -->
                <values>
                  <value description="Norway NOK">nok</value>
                  <value description="Sweden SEK">sek</value>
                  <value description="Denmark DKK">dkk</value>
                </values>
              </attribute>
            </attributes>
          </service>
        </services>
      </product>
    </products>
  </transport-agreement>
</transport-agreements>
```

## Query String Parameters
You can filter the returned list of transport agreements by supplying a `carrier`or `product` identifier.
A filter will only return transport agreements having the specified parameter. Will speed up response time if you have a lot of transport agreements activated in Cargonizer.
* carrier
* product

Returning only transport agreements with the carrier Bring
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' \
'https://api.cargonizer.no/transport_agreements.xml?carrier_id=bring'


```
Returning only transport agreements with the produc Servicepakke
```bash
curl -g -XGET -H'X-Cargonizer-Key: 12345' -H'X-Cargonizer-Sender: 678' \
'https://api.cargonizer.no/transport_agreements.xml?product_id=bring_servicepakke'
```

## Live checkout extension phase policy (added 2026-04-09)

This section governs all work that extends the plugin from admin-only usage to live checkout shipping in WooCommerce.

- Checkout implementation must be strictly additive in this phase. Existing admin estimator, admin modal UX, and admin booking flow must remain intact unless a prompt explicitly authorizes a behavior change.
- Runtime parity for current admin behavior is mandatory:
  - keep all current auth behavior unchanged,
  - keep all current endpoint usage unchanged,
  - keep all current pricing/estimate/booking logic unchanged,
  - keep existing booking state storage in `_lp_cargonizer_booking_state` unchanged.
- The checkout solution must be implemented as one real WooCommerce shipping method/integration that can be added to shipping zones and can return multiple real WooCommerce rates from one engine.
- Pickup-point capable methods must use a rate-attached pickup point data model that works in embedded checkout experiences.
- Pickup-point metadata contract must use these keys exactly:
  - `krokedil_pickup_points`
  - `krokedil_selected_pickup_point`
  - `krokedil_selected_pickup_point_id`
- Both classic checkout and Store API order-save paths must be supported when persisting the selected shipping method + pickup point.
- Quote requests and pickup-point requests must be cached with safe invalidation.
- Nearest pickup point must be preselected automatically while still allowing customer override.
- Any new settings for checkout must be backward-compatible extensions inside `lp_cargonizer_settings` (no breaking rename/removal of existing keys).
