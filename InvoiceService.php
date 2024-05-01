<?php
if (!defined('BASEPATH')) exit('No direct script access allowed');

class InvoiceService
{
    private $ci;
    private $mailer;
	private $referralType;

    public function __construct($mailer)
    {
        $this->ci =& get_instance();
        $this->mailer = $mailer;
    }

    // Will be use for xero as well
    public function getInvoiceData($referralId, $referralType, $completionDate, $closureDate)
    {
		$this->referralType = $referralType;
        $facilitators = $this->ci->fac->get_facilitators($referralId, 1);
        $conferences = $this->getInvoiceConferences($id, $completionDate);
		$travels = $this->ci->conference_facs->get_fac_conf($referralId);
		$addOns = $this->getAddOnItems($referralId);
        $minDistanceTravel = $this->ci->setting->find(6);

        foreach ($facilitators as $facilitator) {
			$facilitatorInfo = $this->getfacilitatorInfo($facilitator);
            $invoiceItems = $this->calculateInvoiceItems($facilitator, $conferences, $travels, $minDistanceTravel);
			$addOnItems = $this->calculateAddOnItems($facilitator, $addOns);
			$data[$facilitator->facilitator_id] = array_merge(
				$facilitatorInfo, $invoiceItems, $addOnItems
			);
        }

        return $data;
    }

    // Private functions
    private function getInvoiceConferences($referralId, $completionDate)
    {
        return $this->ci->conference
			->where(['document_id' => $referralId, "conference_date" => " >= {$completionDate}"])
            ->order_by('fac_id, conference_type DESC')
            ->find();
    }

	private function getAddOnItems($referralId) {
		$data = [];
		$addOns = $this->ci->addons->find(['referral_id'=>$referralId]);
		foreach($addOns as $row) {
			$data[$row->fac_id] = [
				'description' => $row->description,
				'amount' => $row->amount,
				'glCode' => $row->gl_code,
			];
		}

		return $data;
	}

    private function isFacilitatorAccredited($facilitatorStatus)
    {
        return str_contains($facilitatorStatus, 'Accredited');
    }

    private function getInvoicingFeeType($facilitatorStatus)
    {
		$feeMatrix = $this->ci->fee->get_fee_matrix();

        return $this->isFacilitatorAccredited($facilitatorStatus) ? 
            $feeMatrix['accredited'] : $feeMatrix['non-accredited'];
    }

    private function getConferenceCount($conferences)
    {
        $standardConferenceCount = 0;
        $preConferenceCount = 0;
        foreach ($conferences as $conference) {
            $conference->conference_type == 'Pre' ? $preConferenceCount++ : $standardConferenceCount++;
        }

        return ['standardConference' => $standardConferenceCount, 'preConference' => $preConferenceCount];
    }

    private function buildFeeCode($conference, $conferenceCounter, $isLead)
    {
        $feeCode = $isLead ? 'L' : 'C';
        $feeCode .= $this->referralType != 'FV' ? 'S' : 'F';
        $feeCode .= $conference->type != 'Pre' ? 'PC' : 'S';

        if ($conference->no_participant_date_one || $conference->no_participant_date_two) {
            $feeCode .= '2';
        } else {
            $counterKey = $conference->type != 'Pre' ? 'standardConference' : 'preConference';
            $feeCode .= $conferenceCounter[$counterKey] <= 2 ? '1' : '2';
        }

        return $feeCode;
    }

	private function getFacilitatorInfo($facilitator)
    {
        return [
            'facilitator' => [
                'first_name' => $facilitator->first_name,
                'last_name' => $facilitator->last_name,
                'address_line_1' => $facilitator->address_line_1,
                'address_line_2' => $facilitator->address_line_2,
                'gst_number' => $facilitator->gst_number,
            ]
        ];
    }

    private function calculateInvoiceItems($facilitator, $conferences, $travels, $minDistanceTravel)
	{
		$data = [];
		$feeType = $this->getInvoicingFeeType($facilitator->facilitator_status);

		if (empty($conferences)) {
			//If there are NO Pre-conferences or Conferences then fee applied is the ‘Referral Assignment fee’
			$feeCode = 'RAF';
			return $data[] = [
				'conference_id' => '',
				'description' => 'Referral Assignment fee',
				'conference_date' => $conference->conference_date,
				'qty' => 1,
				'amount' => $feeType[$feeCode]->fee_amount,
				'gl_code' => $feeType[$feeCode]->gl_code
			];
		}

		$conferenceCounter = $this->getConferenceCount($conferences);
		foreach ($conferences as $conference) {
			$isLead = $conference->lead_id == $facilitator->id;
			$feeCode = $this->buildFeeCode($conference, $conferenceCounter, $isLead);

			// TODO: Convert to function
			$data[] = [
				'conference_id' => $conference->id,
				'description' => $conference->conference_type,
				'conference_date' => $conference->conference_date,
				'qty' => 1,
				'amount' => $feeType[$feeCode]->fee_amount,
				'gl_code' => $feeType[$feeCode]->gl_code
			];

			// TODO: Convert to function
			$travelTime = $travels[$facilitator->id][$conference->id]->travel_time;
            $travelDistance = $travels[$facilitator->id][$conference->id]->travel_distance;

			if ($travelDistance < $minDistanceTravel) {
				$feeCode = 'MTF';
				$data[] = [
					'conference_id' => $conference->id,
					'description' => 'Minimum Travel Distance',
					'conference_date' => $conference->conference_date,
					'qty' => $travelDistance,
					'amount' => $feeType[$feeCode]->fee_amount,
					'gl_code' => $feeType[$feeCode]->gl_code
				];
			} else {
				$feeCode = 'PKTF';
				$data[] = [
					'conference_id' => $conference->id,
					'description' => 'Travel Distance',
					'conference_date' => $conference->conference_date,
					'qty' => $travelDistance,
					'amount' => $feeType[$feeCode]->fee_amount,
					'gl_code' => $feeType[$feeCode]->gl_code
				];
			}

			// TODO: Convert to function
			if ($travelTime >= 1) {
				$data[] = [
					'conference_id' => $conference->id,
					'description' => 'Travel Time',
					'conference_date' => $conference->conference_date,
					'qty' => $travelTime,
					'amount' => $feeType['PHTF']->fee_amount,
					'gl_code' => $feeType['PHTF']->gl_code
				];
			}
		}

		return $data;
	}

	private function calculateAddonItems($facilitator, $addOns) {
		$data = [];
		foreach($addOns[$facilitator->id] as $row) {
			$data[] = [
				'conference_id' => '',
				'description' => $row->description,
				'conference_date' => '',
				'qty' => 1,
				'amount' => $row->amount,
				'gl_code' => $row->glCode,
			];
		}

		return $data;
	}

	private function getNextInvoiceNumber()
    {
        $invoice_number = $this->ci->setting->first(3);
        $invNumber = $invoice_number->value;
        $this->ci->setting->save(['id' => 3, 'value' => $invNumber + 1]);

        return 'WGT' . str_pad($invNumber, 5, '0', STR_PAD_LEFT);
    }
}
?>
