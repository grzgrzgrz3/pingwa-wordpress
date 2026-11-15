<?php
/**
 * Each form plugin's submission shape mapped to the plugin's field list
 * (label, value, type), from which Pingwa_Payload::form() builds the body.
 *
 * @package pingwa
 */

use PHPUnit\Framework\TestCase;

/**
 * A Gravity Forms field as GF passes it (GF_Field: public props + get_value_export()).
 */
final class Fake_GF_Field {
	public $id;
	public $type;
	public $label;
	public $adminLabel = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	public $visibility = 'visible';
	private $export;

	public function __construct( $id, $type, $label, $export = null, $visibility = 'visible' ) {
		$this->id         = $id;
		$this->type       = $type;
		$this->label      = $label;
		$this->export     = $export;
		$this->visibility = $visibility;
	}

	public function get_input_type() {
		return $this->type;
	}

	public function get_value_export( $entry ) {
		return null === $this->export ? ( isset( $entry[ (string) $this->id ] ) ? $entry[ (string) $this->id ] : '' ) : $this->export;
	}
}

final class FieldsTest extends TestCase {

	public function test_cf7_tags_and_posted_data() {
		$tags   = array(
			(object) array( 'basetype' => 'text', 'name' => 'your-name' ),
			(object) array( 'basetype' => 'email', 'name' => 'your-email' ),
			(object) array( 'basetype' => 'checkbox', 'name' => 'topics' ),
			(object) array( 'basetype' => 'hidden', 'name' => 'ref' ),
			(object) array( 'basetype' => 'file', 'name' => 'cv' ),
			(object) array( 'basetype' => 'submit', 'name' => '' ),
		);
		$posted = array(
			'your-name'  => 'Ada',
			'your-email' => 'ada@example.com',
			'topics'     => array( 'Sales' ),
			'ref'        => 'abc',
			'cv'         => 'cv.pdf',
			'_wpcf7'     => '5',
		);
		$this->assertSame(
			array(
				array( 'label' => 'Your name', 'value' => 'Ada', 'type' => 'text', 'name' => 'your-name' ),
				array( 'label' => 'Your email', 'value' => 'ada@example.com', 'type' => 'email', 'name' => 'your-email' ),
				array( 'label' => 'Topics', 'value' => array( 'Sales' ), 'type' => 'checkbox', 'name' => 'topics' ),
				array( 'label' => 'Ref', 'value' => 'abc', 'type' => 'hidden', 'name' => 'ref' ),
				array( 'label' => 'Cv', 'value' => 'cv.pdf', 'type' => 'file', 'name' => 'cv' ),
			),
			Pingwa_Fields::from_cf7( $tags, $posted )
		);
	}

	public function test_wpforms_fields() {
		$fields = array(
			1 => array( 'name' => 'Name', 'value' => 'Ada Lovelace', 'id' => 1, 'type' => 'name' ),
			2 => array( 'name' => 'Password', 'value' => 'x', 'id' => 2, 'type' => 'password' ),
			3 => array( 'name' => '', 'value' => 'hi', 'id' => 3, 'type' => 'textarea' ),
		);
		$this->assertSame(
			array(
				array( 'label' => 'Name', 'value' => 'Ada Lovelace', 'type' => 'name', 'name' => '1' ),
				array( 'label' => 'Password', 'value' => 'x', 'type' => 'password', 'name' => '2' ),
				array( 'label' => 'Field 3', 'value' => 'hi', 'type' => 'textarea', 'name' => '3' ),
			),
			Pingwa_Fields::from_wpforms( $fields )
		);
	}

	public function test_elementor_record_fields() {
		$fields = array(
			'name'    => array( 'id' => 'name', 'type' => 'text', 'title' => 'Name', 'value' => 'Ada', 'raw_value' => 'Ada' ),
			'message' => array( 'id' => 'message', 'type' => 'textarea', 'title' => '', 'value' => 'hi', 'raw_value' => 'hi' ),
			'hp'      => array( 'id' => 'hp', 'type' => 'honeypot', 'title' => '', 'value' => '', 'raw_value' => '' ),
		);
		$this->assertSame(
			array(
				array( 'label' => 'Name', 'value' => 'Ada', 'type' => 'text', 'name' => 'name' ),
				array( 'label' => 'Message', 'value' => 'hi', 'type' => 'textarea', 'name' => 'message' ),
				array( 'label' => 'Hp', 'value' => '', 'type' => 'honeypot', 'name' => 'hp' ),
			),
			Pingwa_Fields::from_elementor( $fields )
		);
	}

	public function test_gravity_forms_fields() {
		$form  = array(
			'title'  => 'Quote',
			'fields' => array(
				new Fake_GF_Field( 1, 'name', 'Name', 'Ada Lovelace' ),
				new Fake_GF_Field( 2, 'email', 'Email' ),
				new Fake_GF_Field( 3, 'text', 'Internal', null, 'administrative' ),
				new Fake_GF_Field( 4, 'text', 'Secret', null, 'hidden' ),
				new Fake_GF_Field( 5, 'fileupload', 'CV' ),
			),
		);
		$entry = array( '2' => 'ada@example.com', '3' => 'x', '4' => 'y', '5' => 'cv.pdf' );
		$this->assertSame(
			array(
				array( 'label' => 'Name', 'value' => 'Ada Lovelace', 'type' => 'name', 'name' => '1' ),
				array( 'label' => 'Email', 'value' => 'ada@example.com', 'type' => 'email', 'name' => '2' ),
				array( 'label' => 'Internal', 'value' => 'x', 'type' => 'hidden', 'name' => '3' ),
				array( 'label' => 'Secret', 'value' => 'y', 'type' => 'hidden', 'name' => '4' ),
				array( 'label' => 'CV', 'value' => 'cv.pdf', 'type' => 'fileupload', 'name' => '5' ),
			),
			Pingwa_Fields::from_gravity( $form, $entry )
		);
	}

	public function test_mapped_fields_end_up_without_skipped_ones() {
		$fields = Pingwa_Fields::from_gravity(
			array(
				'fields' => array(
					new Fake_GF_Field( 1, 'text', 'Name' ),
					new Fake_GF_Field( 2, 'text', 'Internal', null, 'administrative' ),
					new Fake_GF_Field( 3, 'fileupload', 'CV' ),
				),
			),
			array( '1' => 'Ada', '2' => 'x', '3' => 'cv.pdf' )
		);
		$p      = Pingwa_Payload::form( 'Quote', $fields, 'gravityforms', 'S' );
		$this->assertSame( array( array( 'label' => 'Name', 'value' => 'Ada' ) ), $p['data']['fields'] );
	}

	public function test_bad_input_maps_to_nothing() {
		$this->assertSame( array(), Pingwa_Fields::from_cf7( null, null ) );
		$this->assertSame( array(), Pingwa_Fields::from_wpforms( 'x' ) );
		$this->assertSame( array(), Pingwa_Fields::from_elementor( array( 'x', 1 ) ) );
		$this->assertSame( array(), Pingwa_Fields::from_gravity( array(), array() ) );
	}
}
