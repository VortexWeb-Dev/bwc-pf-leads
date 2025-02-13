<?php

function extractDetails($body, $type = 'email')
{
    $details = [];

    if ($type === 'email') {
        if (preg_match('/Agent:\s*([^\n]+)/', $body, $matches)) {
            $details['agent_name'] = trim($matches[1]);
        }

        if (preg_match('/You can respond to\s+([^\n]+?)\s+calling or emailing on:[\s\S]*?([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $body, $matches)) {
            $details['client_name'] = trim($matches[1]);
            $details['client_email'] = trim($matches[2]);
        }

        if (preg_match('/\+(\d{12}|\d{11})/', $body, $matches)) {
            $details['client_phone'] = '+' . $matches[1];
        }

        if (preg_match('/([\d,]+)\s*AED/', $body, $matches)) {
            $details['property_price'] = str_replace(',', '', $matches[1]);
        }

        if (preg_match('/(Apartment|Villa)\s*-\s*(\d+)\s*🛏\s*(\d+)\s*🛁\s*-\s*([\d,]+)\s*sqft/', $body, $matches)) {
            $details['property_type'] = $matches[1];
            $details['bedrooms'] = $matches[2];
            $details['bathrooms'] = $matches[3];
            $details['property_size'] = str_replace(',', '', $matches[4]);
        }

        if (preg_match('/Reference:\s*([\w-]+)/', $body, $matches)) {
            $details['property_reference'] = trim($matches[1]);
        }

        if (preg_match('/((?:Open Layout|DIFC View|Close to Metro|Furnished|Huge Balcony|Sea View|Lagoon View|Great Community|Ready to Move In|High Floor|Bills Included|Upgraded|VACANT|WELL MAINTAINED|UNFURNISHED)(?:\s*\|\s*(?:Open Layout|DIFC View|Close to Metro|Furnished|Huge Balcony|Sea View|Lagoon View|Great Community|Ready to Move In|High Floor|Bills Included|Upgraded|VACANT|WELL MAINTAINED|UNFURNISHED))*)/', $body, $matches)) {
            $details['property_features'] = trim($matches[1]);
        }
    } else if ($type === 'call') {
        if (preg_match('/Dear\s+([^,\n]+)/', $body, $matches)) {
            $details['agent_name'] = trim($matches[1]);
        }

        if (preg_match('/\+(\d{12}|\d{11})/', $body, $matches)) {
            $details['client_phone'] = '+' . $matches[1];
        }

        if (preg_match('/Call start\s*:\s*([\d-]+T[\d:]+Z)/', $body, $matches)) {
            $details['call_start'] = trim($matches[1]);
        }

        if (preg_match('/Call end\s*:\s*([\d-]+T[\d:]+Z)/', $body, $matches)) {
            $details['call_end'] = trim($matches[1]);
        }

        if (preg_match('/Talk time\s*:\s*(\d+s)/', $body, $matches)) {
            $details['talk_time'] = trim($matches[1]);
        }

        if (preg_match('/Waiting time\s*:\s*(\d+s)/', $body, $matches)) {
            $details['waiting_time'] = trim($matches[1]);
        }

        if (isset($details['call_start']) && isset($details['call_end'])) {
            $start = new DateTime($details['call_start']);
            $end = new DateTime($details['call_end']);
            $details['duration_seconds'] = $end->getTimestamp() - $start->getTimestamp();
        }
    }

    return $details;
}

function logRequest($data)
{
    logToFile("logs/email_log.txt", $data);
}

function logToFile($filePath, $data)
{
    $logEntry = "[" . date("Y-m-d H:i:s") . "]\n";

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $logEntry .= "$key:\n";
            foreach ($value as $subKey => $subValue) {
                $logEntry .= "    $subKey: $subValue\n";
            }
        } else {
            $logEntry .= "$key: $value\n";
        }
    }

    $logEntry .= str_repeat("-", 40) . "\n\n";

    file_put_contents($filePath, $logEntry, FILE_APPEND | LOCK_EX);
}

function formatCurrencyString($input)
{
    $currencySymbols = [
        '£' => 'GBP',
        '€' => 'EUR',
        '$' => 'USD',
        '¥' => 'JPY'
    ];

    $symbol = mb_substr($input, 0, 1);
    $numericValue = preg_replace('/[^\d.-]/', '', $input);
    $currencyCode = $currencySymbols[$symbol] ?? 'EUR';

    return $numericValue . '|' . $currencyCode;
}

function createBitrixLead($fields)
{
    $response = CRest::call('crm.lead.add', [
        'fields' => $fields
    ]);

    if (isset($response['error'])) {
        logToFile("logs/error.txt", [
            "Error" => "Bitrix API Error: " . $response['error_description'],
            "Fields" => $fields
        ]);
        return null; // Return null if there's an error
    }

    // Check if the lead ID is available
    if (isset($response['result'])) {
        return $response['result']; // Return the new lead ID
    } else {
        logToFile("logs/error.txt", [
            "Error" => "No ID returned in Bitrix response",
            "Response" => $response,
            "Fields" => $fields
        ]);
        return null; // Return null if no lead ID is found
    }
}

function checkExistingContact($filter = [])
{
    $response = CRest::call('crm.contact.list', [
        'filter' => $filter,
        'select' => ['ID', 'EMAIL']
    ]);

    if (isset($response['result']) && $response['total'] > 0) {
        // Check if we have a valid ID and return it
        if (isset($response['result'][0]['ID'])) {
            return $response['result'][0]['ID'];
        }
    }

    return null;
}

function createContact($fields)
{
    $response = CRest::call('crm.contact.add', [
        'fields' => $fields
    ]);

    return $response['result'];
}

function getListingAgent($property_reference)
{
    $response = CRest::call('crm.item.list', [
        'entityTypeId' => 1046,
        'filter' => [
            'ufCrm5ReferenceNumber' => $property_reference
        ],
        'select' => [
            'ufCrm5AgentName',
            'ufCrm5ReferenceNumber'
        ]
    ]);

    if (isset($response['result']['items'][0]['ufCrm5AgentName'])) {

        return trim($response['result']['items'][0]['ufCrm5AgentName']);
    }

    return null;
}

function getListingOwner($property_reference)
{
    $response = CRest::call('crm.item.list', [
        'entityTypeId' => 1046,
        'filter' => [
            'ufCrm5ReferenceNumber' => $property_reference
        ],
        'select' => [
            'ufCrm5ListingOwner',
            'ufCrm5ReferenceNumber'
        ]
    ]);

    if (isset($response['result']['items'][0]['ufCrm5ListingOwner'])) {

        return trim($response['result']['items'][0]['ufCrm5ListingOwner']);
    }

    return null;
}

function getUser($filter = [])
{
    $response = CRest::call('user.get', [
        'filter' => $filter,
        'select' => ['ID', 'NAME']
    ]);

    if (isset($response['result'][0]['ID'])) {
        return $response['result'][0]['ID'];
    }

    return null;
}

function determineOwnerId($owner_name)
{
    $nameParts = explode(' ', $owner_name);

    $firstName = $nameParts[0];
    $secondName = isset($nameParts[1]) && count($nameParts) > 2 ? $nameParts[1] : '';
    $lastName = count($nameParts) > 2 ? $nameParts[count($nameParts) - 1] : (isset($nameParts[1]) ? $nameParts[1] : '');

    $owner_id = !empty($owner_name) ? getUser(['%NAME' => $firstName, '%LAST_NAME' => $lastName, '%SECOND_NAME' => $secondName]) : 1043;
    return ($owner_id == 433) ? 1043 : $owner_id;
}
