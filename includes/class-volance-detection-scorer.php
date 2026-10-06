<?php
/**
 * Score pipeline for one form submission. Observe-only: it logs and returns,
 * and any problem results in a log row, never a blocked or delayed visitor.
 *
 * @package Volance_Detection
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a submission into (at most) one Volance score and one log row.
 */
class Volance_Detection_Scorer {

	/**
	 * API client (session(), score()).
	 *
	 * @var object
	 */
	private $client;

	/**
	 * Callable that writes one log row.
	 *
	 * @var callable
	 */
	private $logger;

	/**
	 * State object (fingerprints(), set_fingerprints(), backoff_until(), set_backoff(), set_last_error()).
	 *
	 * @var object
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param object   $client API client.
	 * @param callable $logger Log writer, receives one array.
	 * @param object   $state  State holder.
	 */
	public function __construct( $client, $logger, $state ) {
		$this->client = $client;
		$this->logger = $logger;
		$this->state  = $state;
	}

	/**
	 * Score one submission.
	 *
	 * @param string $form             Form key (login, register, comments).
	 * @param string $snapshot_json    Raw snapshot from the hidden field ('' when absent).
	 * @param array  $request_signals  Visitor request description.
	 * @return string Outcome: scored|no_evidence|rejected|skipped|limited|error.
	 */
	public function run( $form, $snapshot_json, array $request_signals ) {
		// Without a consented snapshot nothing is sent to Volance at all.
		if ( '' === $snapshot_json ) {
			return $this->log( $form, 'no_evidence', array( 'note' => 'No consented browser evidence.' ) );
		}

		$evidence = Volance_Detection_Evidence::sanitize_snapshot( $snapshot_json );
		if ( null === $evidence ) {
			return $this->log( $form, 'rejected', array( 'note' => 'Snapshot was invalid or contained content fields.' ) );
		}
		if ( 0 === count( $evidence['events'] ) ) {
			return $this->log( $form, 'no_evidence', array( 'note' => 'Consent given but no interaction was recorded.' ) );
		}

		if ( $this->state->fingerprints() ) {
			return $this->log( $form, 'skipped', array( 'note' => 'Workspace has fingerprinting enabled; plugin does not send.' ) );
		}
		if ( $this->state->backoff_until() > 0 ) {
			return $this->log( $form, 'limited', array( 'note' => 'Paused after a Volance rate limit.' ) );
		}

		$session = $this->client->session();
		if ( ! $session['ok'] ) {
			return $this->failure( $form, $session );
		}
		$collect = ( isset( $session['data']['collect'] ) && is_array( $session['data']['collect'] ) ) ? $session['data']['collect'] : array();
		if ( true === ( $collect['fingerprints'] ?? false ) ) {
			$this->state->set_fingerprints( true );
			return $this->log( $form, 'skipped', array( 'note' => 'Workspace has fingerprinting enabled; plugin does not send.' ) );
		}
		$sid      = isset( $session['data']['sessionId'] ) ? (string) $session['data']['sessionId'] : '';
		$manifest = isset( $session['data']['manifest'] ) ? (string) $session['data']['manifest'] : '';
		if ( '' === $sid || '' === $manifest ) {
			return $this->log( $form, 'error', array( 'note' => 'Volance returned an unexpected session.' ) );
		}

		$payload = array(
			'sid'            => $sid,
			'manifest'       => $manifest,
			'clientSignals'  => (object) $evidence['clientSignals'],
			'events'         => $evidence['events'],
			'requestSignals' => (object) $request_signals,
		);

		$scored = $this->client->score( $payload );
		if ( ! $scored['ok'] ) {
			return $this->failure( $form, $scored );
		}

		$data = $scored['data'];
		$meta = isset( $data['evidence'] ) && is_array( $data['evidence'] ) ? $data['evidence'] : array();
		$this->state->set_last_error( '' );
		return $this->log(
			$form,
			'scored',
			array(
				'score'       => isset( $data['score'] ) && is_numeric( $data['score'] ) ? (float) $data['score'] : null,
				'verdict'     => isset( $data['verdict'] ) && is_string( $data['verdict'] ) ? $data['verdict'] : '',
				'label'       => isset( $data['classification']['label'] ) && is_string( $data['classification']['label'] ) ? $data['classification']['label'] : '',
				'events'      => isset( $meta['events'] ) ? (int) $meta['events'] : count( $evidence['events'] ),
				'behavioural' => isset( $meta['behavioural'] ) ? (bool) $meta['behavioural'] : true,
				'request_id'  => isset( $data['requestId'] ) && is_string( $data['requestId'] ) ? $data['requestId'] : '',
			)
		);
	}

	/**
	 * Record a failed API call and, for 429, pause further scoring.
	 *
	 * @param string $form   Form key.
	 * @param array  $result Client result.
	 * @return string Outcome.
	 */
	private function failure( $form, array $result ) {
		$this->state->set_last_error( $result['error'] );
		if ( 429 === $result['status'] ) {
			$this->state->set_backoff( $result['retry_after'] );
			return $this->log( $form, 'limited', array( 'note' => $result['error'] ) );
		}
		return $this->log( $form, 'error', array( 'note' => $result['error'] ) );
	}

	/**
	 * Write a log row and return the status.
	 *
	 * @param string $form   Form key.
	 * @param string $status Outcome.
	 * @param array  $extra  Extra columns.
	 * @return string The status.
	 */
	private function log( $form, $status, array $extra = array() ) {
		call_user_func(
			$this->logger,
			array_merge(
				array(
					'form'   => $form,
					'status' => $status,
				),
				$extra
			)
		);
		return $status;
	}
}
