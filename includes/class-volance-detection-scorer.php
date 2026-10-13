<?php
/**
 * Score pipeline for one protected request. It logs every outcome and returns
 * what Volance recommended and what the plugin should apply for the form's mode.
 * Any problem (timeout, quota, error) fails open: the request is allowed.
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
	 * State object (backoff_until(), set_backoff(), set_last_error()).
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
	 * Score one request and return its status only.
	 *
	 * @param string $form             Form key.
	 * @param string $snapshot_json    Raw snapshot from the hidden field ('' when absent).
	 * @param array  $request_signals  Visitor request description.
	 * @return string Outcome: scored|no_evidence|rejected|skipped|limited|error.
	 */
	public function run( $form, $snapshot_json, array $request_signals ) {
		$result = $this->evaluate( $form, $snapshot_json, $request_signals );
		return $result['status'];
	}

	/**
	 * Score one request and decide what to do with it.
	 *
	 * @param string $form            Form key.
	 * @param string $snapshot_json   Raw snapshot from the hidden field ('' when absent).
	 * @param array  $request_signals Visitor request description.
	 * @param array  $options         mode (observe|flag|block), score_without_evidence (bool),
	 *                                disclosure (signed-agent headers).
	 * @return array {status:string, recommended:string, applied:string, score:?float, label:string}
	 */
	public function evaluate( $form, $snapshot_json, array $request_signals, array $options = array() ) {
		$mode       = isset( $options['mode'] ) ? (string) $options['mode'] : 'observe';
		$disclosure = ( isset( $options['disclosure'] ) && is_array( $options['disclosure'] ) ) ? $options['disclosure'] : array();
		// A signed agent never runs the page script, so it is always worth scoring.
		$allow_empty = ! empty( $options['score_without_evidence'] ) || ! empty( $disclosure );

		$evidence = array(
			'clientSignals' => array(),
			'events'        => array(),
		);
		$problem  = '';

		if ( '' === $snapshot_json ) {
			$problem = 'no_evidence';
		} else {
			$parsed = Volance_Detection_Evidence::sanitize_snapshot( $snapshot_json );
			if ( null === $parsed ) {
				$problem = 'rejected';
			} elseif ( 0 === count( $parsed['events'] ) ) {
				$problem = 'no_evidence';
			} else {
				$evidence = $parsed;
			}
		}

		if ( '' !== $problem && ! $allow_empty ) {
			$notes = array(
				'no_evidence' => __( 'No browser evidence.', 'volance-detection' ),
				'rejected'    => __( 'Snapshot was invalid or contained content fields.', 'volance-detection' ),
			);
			return $this->finish( $form, $problem, array( 'note' => $notes[ $problem ] ) );
		}

		if ( $this->state->backoff_until() > 0 ) {
			return $this->finish( $form, 'limited', array( 'note' => __( 'Paused after a Volance rate limit.', 'volance-detection' ) ) );
		}

		$session = $this->client->session();
		if ( ! $session['ok'] ) {
			return $this->failure( $form, $session );
		}
		// Same rule as the Volance API: fingerprint-tier fields travel only while the workspace has the tier on.
		$collect = isset( $session['data']['collect'] ) && is_array( $session['data']['collect'] ) ? $session['data']['collect'] : array();
		$tier_on = true === ( $collect['fingerprints'] ?? false );
		if ( $tier_on !== $this->state->fingerprints() ) {
			$this->state->set_fingerprints( $tier_on ); // Keeps the page script's setting current.
		}
		if ( ! $tier_on ) {
			$evidence['clientSignals'] = Volance_Detection_Evidence::strip_fingerprints( $evidence['clientSignals'] );
		}
		$sid      = isset( $session['data']['sessionId'] ) ? (string) $session['data']['sessionId'] : '';
		$manifest = isset( $session['data']['manifest'] ) ? (string) $session['data']['manifest'] : '';
		if ( '' === $sid || '' === $manifest ) {
			return $this->finish( $form, 'error', array( 'note' => __( 'Volance returned an unexpected session.', 'volance-detection' ) ) );
		}

		$payload = array(
			'sid'            => $sid,
			'manifest'       => $manifest,
			'clientSignals'  => (object) $evidence['clientSignals'],
			'events'         => $evidence['events'],
			'requestSignals' => (object) $request_signals,
		);
		if ( ! empty( $options['page'] ) && is_string( $options['page'] ) ) {
			$payload['page'] = substr( $options['page'], 0, 256 );
		}
		if ( $disclosure ) {
			$payload['disclosureHeaders'] = (object) $disclosure;
		}

		$scored = $this->client->score( $payload );
		if ( ! $scored['ok'] ) {
			return $this->failure( $form, $scored );
		}

		$data        = $scored['data'];
		$meta        = isset( $data['evidence'] ) && is_array( $data['evidence'] ) ? $data['evidence'] : array();
		$compact     = Volance_Detection_Response::compact( $data );
		$recommended = Volance_Detection_Decision::normalize_action( $data['action'] ?? '' );
		$enforce     = ! array_key_exists( 'enforce', $data ) || (bool) $data['enforce'];
		$applied     = Volance_Detection_Decision::resolve( $mode, $recommended, $enforce );
		$note        = isset( $options['note'] ) ? (string) $options['note'] : '';
		if ( ! $enforce && Volance_Detection_Decision::is_synchronous( $mode ) && Volance_Detection_Decision::ALLOW !== $recommended ) {
			$note = trim( $note . ' [' . __( 'Workspace enforcement is off', 'volance-detection' ) . ']' );
		}
		$score       = isset( $data['score'] ) && is_numeric( $data['score'] ) ? (float) $data['score'] : null;
		$label       = isset( $data['classification']['label'] ) && is_string( $data['classification']['label'] ) ? $data['classification']['label'] : '';

		$this->state->set_last_error( '' );
		return $this->finish(
			$form,
			'scored',
			array(
				'score'       => $score,
				'verdict'     => isset( $data['verdict'] ) && is_string( $data['verdict'] ) ? $data['verdict'] : '',
				'label'       => $label,
				'events'      => isset( $meta['events'] ) ? (int) $meta['events'] : count( $evidence['events'] ),
				'behavioural' => isset( $meta['behavioural'] ) ? (bool) $meta['behavioural'] : ! empty( $evidence['events'] ),
				'request_id'  => isset( $data['requestId'] ) && is_string( $data['requestId'] ) ? $data['requestId'] : '',
				'agent_type'  => Volance_Detection_Response::agent_type( $compact ),
				'response'    => $compact,
				'action'      => $recommended,
				'applied'     => $applied,
				'note'        => substr( $note, 0, 190 ),
			),
			$recommended,
			$applied,
			$score,
			$label
		);
	}

	/**
	 * Record a failed API call and, for 429, pause further scoring.
	 *
	 * @param string $form   Form key.
	 * @param array  $result Client result.
	 * @return array Result (always allowed).
	 */
	private function failure( $form, array $result ) {
		$this->state->set_last_error( $result['error'] );
		if ( 429 === $result['status'] ) {
			$this->state->set_backoff( $result['retry_after'] );
			return $this->finish( $form, 'limited', array( 'note' => $result['error'] ) );
		}
		return $this->finish( $form, 'error', array( 'note' => $result['error'] ) );
	}

	/**
	 * Write a log row and return the result.
	 *
	 * @param string      $form        Form key.
	 * @param string      $status      Outcome.
	 * @param array       $extra       Extra columns.
	 * @param string      $recommended Volance's action.
	 * @param string      $applied     Action the plugin applies.
	 * @param float|null  $score       Score.
	 * @param string      $label       Classification label.
	 * @return array {status, recommended, applied, score, label}
	 */
	private function finish( $form, $status, array $extra = array(), $recommended = 'allow', $applied = 'allow', $score = null, $label = '' ) {
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
		return array(
			'status'      => $status,
			'recommended' => $recommended,
			'applied'     => $applied,
			'score'       => $score,
			'label'       => $label,
		);
	}
}
