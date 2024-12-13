<?php

header('Content-Type: application/json; charset=utf-8');
$campaigns = include './config/campaign_data.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$bidRequestJson = file_get_contents('php://input');

	try {
		$bidRequest = json_decode($bidRequestJson, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception("Invalid JSON format: " . json_last_error_msg());
		}

		$selectedCampaign = handleAndValidateBidRequest($bidRequest, $campaigns);

		if ($selectedCampaign) {
			echo json_encode([
				'status' => 'success',
				'campaign' => [
					'name' => $selectedCampaign['campaignname'],
					'advertiser' => $selectedCampaign['advertiser'],
					'creative_type' => $selectedCampaign['creative_type'],
					'image_url' => $selectedCampaign['image_url'],
					'landing_page_url' => $selectedCampaign['url'],
					'bid_price' => $selectedCampaign['price'],
					'ad_id' => $selectedCampaign['code'],
					'creative_id' => $selectedCampaign['creative_id']
				]
			]);
		} else {
			throw new Exception("No suitable campaign found");
		}
	} catch (Exception $e) {
		http_response_code(400);
		echo json_encode([
			'status' => 'error',
			'message' => $e->getMessage(),
		]);
	}
} else {
	http_response_code(405);
	echo json_encode([
		'status' => 'error',
		'message' => 'Invalid request method. Please use POST.',
	]);
}

function handleAndValidateBidRequest(array $bidRequest, array $campaigns): ?array
{
	if (empty($bidRequest['imp']) || !is_array($bidRequest['imp'])) {
		throw new Exception("Missing or invalid 'imp' field");
	}

	$highestBidPrice = 0;
	$bestCampaign = null;

	foreach ($bidRequest['imp'] as $imp) {
		validateImp($imp);
		foreach ($campaigns as $campaign) {
			if (isCampaignEligible($campaign, $imp, $bidRequest)) {
				if ($campaign['price'] > $highestBidPrice) {
					$highestBidPrice = $campaign['price'];
					$bestCampaign = $campaign;
				}
			}
		}
	}

	return $bestCampaign;
}

function validateImp(array $imp): void
{
	if (empty($imp['id'])) {
		throw new Exception("Each impression must have an 'id'");
	}

	if (empty($imp['banner']) || !isset($imp['banner']['w']) || !isset($imp['banner']['h'])) {
		throw new Exception("Each impression must include a 'banner' with width and height");
	}

	if (isset($imp['bidfloor']) && (!is_numeric($imp['bidfloor']) || $imp['bidfloor'] < 0)) {
		throw new Exception("Invalid bid floor value");
	}
}

function isCampaignEligible(array $campaign, array $imp, array $bidRequest): bool
{
	$osMatch = in_array(strtolower($bidRequest['device']['os']), explode(',', strtolower($campaign['hs_os'])));
	$countryMatch = strtolower($campaign['country']) === strtolower($bidRequest['device']['geo']['country']);
	$dimensionMatch = isset($imp['banner']['format']) && in_array($campaign['dimension'], array_map(function ($f) {
		return $f['w'] . 'x' . $f['h'];
	}, $imp['banner']['format']));
	$bidFloorMatch = $campaign['price'] >= ($imp['bidfloor'] ?? 0);

	return $osMatch && $countryMatch && $dimensionMatch && $bidFloorMatch;
}
