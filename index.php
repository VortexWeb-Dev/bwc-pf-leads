<?php
require_once(__DIR__ . '/crest/crest.php');
require_once(__DIR__ . '/utils.php');

date_default_timezone_set('Asia/Dubai');

$data = json_decode(file_get_contents("php://input"), true);

$logEntry = "[" . date('Y-m-d H:i:s') . "] " . print_r($data, true) . PHP_EOL;
file_put_contents('logs/webhook.log', $logEntry, FILE_APPEND | LOCK_EX);

if ($data) {
    try {
        $subject = isset($data['subject']) ? htmlspecialchars(trim($data['subject'])) : 'No Subject';
        $from = isset($data['from']) ? htmlspecialchars(trim($data['from'])) : 'Unknown Sender';
        $body = isset($data['body']) ? htmlspecialchars(trim($data['body'])) : 'No Body';

        logToFile("logs/body.log", ["body" => $body]);

        $type = (stripos($subject, 'Call summary') !== false) ? 'call' : 'email';

        if ($type === 'email') {
            $parsedDetails = extractDetails($body);

            $agent_name = $parsedDetails['agent_name'] ?? '';
            $client_name = $parsedDetails['client_name'] ?? '';
            $client_email = $parsedDetails['client_email'] ?? '';
            $client_phone = $parsedDetails['client_phone'] ?? '';
            $property_reference = $parsedDetails['property_reference'] ?? '';
            $property_price = $parsedDetails['property_price'] ?? '';
            $property_size = $parsedDetails['property_size'] ?? '';
            $property_type = $parsedDetails['property_type'] ?? '';
            $bedrooms = $parsedDetails['bedrooms'] ?? '';
            $bathrooms = $parsedDetails['bathrooms'] ?? '';
            $property_features = $parsedDetails['property_features'] ?? '';

            logRequest([
                "Parsed Details" => $parsedDetails,
            ]);

            if (empty($client_phone) && empty($client_email)) {
                throw new Exception("No contact details available for lead");
            }

            if (empty($property_reference)) {
                throw new Exception("No property reference found");
            }

            $existing_contact = checkExistingContact([
                'PHONE' => $client_phone,
                'EMAIL' => $client_email
            ]);

            $comments = "Property Details:\n";
            $comments .= "Reference: $property_reference\n";
            // $comments .= "Price: $property_price AED\n";
            // $comments .= "Size: $property_size sqft\n";
            // $comments .= "Type: $property_type\n";
            // $comments .= "Bedrooms: $bedrooms\n";
            // $comments .= "Bathrooms: $bathrooms\n";
            // $comments .= "Features: $property_features\n";
            $comments .= "\nClient Details:\n";
            $comments .= "Name: $client_name\n";
            $comments .= "Phone: $client_phone\n";
            $comments .= "Email: $client_email\n";
            $comments .= "\nEnquiry from Property Finder";

            $fields = [
                'TITLE' => "Property Finder Lead - $property_reference",
                'COMMENTS' => $comments,
                'UF_CRM_1726164235378' => "631",
                'UF_CRM_1726164328850' => $property_reference,
                'OPPORTUNITY' => $property_price,
                'NAME' => $client_name ?: $client_phone,
                'PHONE' => [
                    [
                        'VALUE' => $client_phone,
                        'VALUE_TYPE' => 'WORK'
                    ]
                ],
                'UF_CRM_1726164292335' => "643",
                'UF_CRM_1726453884158' => Date('d-m-Y'),
            ];

            if (!empty($client_email)) {
                $fields['EMAIL'] = [
                    [
                        'VALUE' => $client_email,
                        'VALUE_TYPE' => 'WORK'
                    ]
                ];
            }

            $agent_id = determineOwnerId($agent_name);
            if (empty($agent_id)) {
                logToFile("logs/error.log", ["Error" => "Invalid agent name", "Agent" => $agent_name]);
            }
            $fields['ASSIGNED_BY_ID'] = $agent_id;

            if (!$existing_contact) {
                $contact_fields = [
                    'NAME' => $client_name,
                    'PHONE' => [
                        [
                            'VALUE' => $client_phone,
                            'VALUE_TYPE' => 'WORK'
                        ]
                    ],
                    'ASSIGNED_BY_ID' => $agent_id,
                    'CREATED_BY_ID' => $agent_id
                ];

                if (!empty($client_email)) {
                    $contact_fields['EMAIL'] = [
                        [
                            'VALUE' => $client_email,
                            'VALUE_TYPE' => 'WORK'
                        ]
                    ];
                }

                $contact_id = createContact($contact_fields);

                if ($contact_id) {
                    $fields['contactId'] = $contact_id;
                } else {
                    logToFile("logs/error.log", ["Error" => "Failed to create contact", "Fields" => $contact_fields]);
                }
            } else {
                $fields['contactId'] = $existing_contact;
            }

            logToFile('logs/listing_owner.log', print_r($fields, true));

            $new_lead_id = createBitrixLead($fields);

            if ($new_lead_id) {
                logToFile("logs/response.log", [
                    'New Lead' => $new_lead_id,
                    'Fields' => $fields
                ]);
                echo json_encode([
                    "status" => "success",
                    "message" => "Lead created successfully",
                    "lead_id" => $new_lead_id
                ]);
            } else {
                throw new Exception("Failed to create new lead");
            }
        } else {
            $parsedDetails = extractDetails($body, 'call');

            $agent_name = $parsedDetails['agent_name'] ?? '';
            $client_phone = $parsedDetails['client_phone'] ?? '';
            $call_start = $parsedDetails['call_start'] ?? '';
            $call_end = $parsedDetails['call_end'] ?? '';
            $talk_time = $parsedDetails['talk_time'] ?? '';
            $waiting_time = $parsedDetails['waiting_time'] ?? '';
            $duration_seconds = $parsedDetails['duration_seconds'] ?? '';

            logRequest([
                "Parsed Details" => $parsedDetails,
            ]);

            if (empty($client_phone)) {
                throw new Exception("No contact details available for lead");
            }

            $existing_contact = checkExistingContact([
                'PHONE' => $client_phone,
            ]);

            $comments = "Call Details:\n";
            $comments .= "Call start: $call_start\n";
            $comments .= "Call end: $call_end\n";
            $comments .= "Talk time: $talk_time\n";
            $comments .= "Waiting time: $waiting_time\n";
            $comments .= "Call duration: $duration_seconds s\n";
            $comments .= "\nClient Details:\n";
            $comments .= "Phone: $client_phone\n";
            $comments .= "\nCall Summary from Property Finder";

            $fields = [
                'TITLE' => "Property Finder Lead - $client_phone",
                'COMMENTS' => $comments,
                'UF_CRM_1726164235378' => "629",
                'NAME' => $client_phone,
                'PHONE' => [
                    [
                        'VALUE' => $client_phone,
                        'VALUE_TYPE' => 'WORK'
                    ]
                ],
                'UF_CRM_1726164292335' => "645",
                'UF_CRM_1726453884158' => Date('d-m-Y'),
            ];

            $agent_id = determineOwnerId($agent_name);
            if (empty($agent_id)) {
                logToFile("logs/error.log", ["Error" => "Invalid agent name", "Agent" => $agent_name]);
            }
            $fields['ASSIGNED_BY_ID'] = $agent_id;

            if (!$existing_contact) {
                $contact_fields = [
                    'NAME' => $client_phone,
                    'PHONE' => [
                        [
                            'VALUE' => $client_phone,
                            'VALUE_TYPE' => 'WORK'
                        ]
                    ],
                    'ASSIGNED_BY_ID' => $agent_id,
                    'CREATED_BY_ID' => $agent_id
                ];

                $contact_id = createContact($contact_fields);

                if ($contact_id) {
                    $fields['contactId'] = $contact_id;
                } else {
                    logToFile("logs/error.log", ["Error" => "Failed to create contact", "Fields" => $contact_fields]);
                }
            } else {
                $fields['contactId'] = $existing_contact;
            }

            logToFile('logs/listing_owner.log', print_r($fields, true));

            $new_lead_id = createBitrixLead($fields);

            if ($new_lead_id) {
                logToFile("logs/response.log", [
                    'New Lead' => $new_lead_id,
                    'Fields' => $fields
                ]);
                echo json_encode([
                    "status" => "success",
                    "message" => "Lead created successfully",
                    "lead_id" => $new_lead_id
                ]);
            } else {
                throw new Exception("Failed to create new lead");
            }
        }
    } catch (Exception $e) {
        logToFile("logs/error.log", [
            "Error" => $e->getMessage(),
            "Data" => $data,
            "Parsed Details" => $parsedDetails ?? []
        ]);
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
        exit;
    }
} else {
    logToFile("logs/email_log.log", ["Error" => "No or invalid data received"]);
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "No data received!"]);
    exit;
}

http_response_code(200);
header('Content-Type: application/json');
