<?php
/**
 * Each form plugin's submission mapped to one field list: label, value, type, name.
 *
 * Skipping (passwords, uploads, hidden fields, spam traps) happens later, in
 * Pingwa_Payload::form(), on the type and name kept here.
 *
 * @package pingwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field mappers. Pure functions over what each plugin passes to its hook.
 */
final class Pingwa_Fields {

	/**
	 * Contact Form 7: the form's tags (WPCF7_FormTag: basetype, name) and the posted
	 * data (WPCF7_Submission::get_posted_data()). CF7 has no labels: the tag name is
	 * made readable ("your-name" → "Your name").
	 *
	 * @param mixed $tags   Form tags.
	 * @param mixed $posted Posted data, by tag name.
	 * @return array
	 */
	public static function from_cf7( $tags, $posted ) {
		if ( ! is_array( $tags ) || ! is_array( $posted ) ) {
			return array();
		}
		$out = array();
		foreach ( $tags as $tag ) {
			$name = self::prop( $tag, 'name' );
			if ( '' === $name || ! array_key_exists( $name, $posted ) ) {
				continue;
			}
			$out[] = self::field( self::humanize( $name ), $posted[ $name ], self::prop( $tag, 'basetype' ), $name );
		}
		return $out;
	}

	/**
	 * WPForms: the `$fields` of wpforms_process_complete (id => name, value, type).
	 *
	 * @param mixed $fields Fields.
	 * @return array
	 */
	public static function from_wpforms( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$out = array();
		foreach ( $fields as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$id    = isset( $field['id'] ) ? (string) $field['id'] : (string) $id;
			$label = self::prop( $field, 'name' );
			$out[] = self::field( '' === $label ? 'Field ' . $id : $label, isset( $field['value'] ) ? $field['value'] : '', self::prop( $field, 'type' ), $id );
		}
		return $out;
	}

	/**
	 * Elementor Pro: Form_Record::get( 'fields' ) (id => id, type, title, value).
	 *
	 * @param mixed $fields Fields.
	 * @return array
	 */
	public static function from_elementor( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}
		$out = array();
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$id    = isset( $field['id'] ) ? (string) $field['id'] : (string) $key;
			$label = self::prop( $field, 'title' );
			$out[] = self::field( '' === $label ? self::humanize( $id ) : $label, isset( $field['value'] ) ? $field['value'] : '', self::prop( $field, 'type' ), $id );
		}
		return $out;
	}

	/**
	 * Gravity Forms: the form's fields (GF_Field objects) and the entry
	 * (gform_after_submission). A field visible only to admins, or hidden, counts as a
	 * hidden field.
	 *
	 * @param mixed $form  Form array.
	 * @param mixed $entry Entry array.
	 * @return array
	 */
	public static function from_gravity( $form, $entry ) {
		if ( ! is_array( $form ) || ! is_array( $entry ) || ! isset( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) || ! isset( $field->id ) ) {
				continue;
			}
			$type = method_exists( $field, 'get_input_type' ) ? (string) $field->get_input_type() : self::prop( $field, 'type' );
			if ( in_array( self::prop( $field, 'visibility' ), array( 'hidden', 'administrative' ), true ) ) {
				$type = 'hidden';
			}
			if ( method_exists( $field, 'get_value_export' ) ) {
				$value = $field->get_value_export( $entry );
			} else {
				$value = isset( $entry[ (string) $field->id ] ) ? $entry[ (string) $field->id ] : '';
			}
			$label = self::prop( $field, 'label' );
			$out[] = self::field( '' === $label ? 'Field ' . $field->id : $label, $value, $type, (string) $field->id );
		}
		return $out;
	}

	/**
	 * One field.
	 *
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param string $type  Type.
	 * @param string $name  Name.
	 * @return array
	 */
	private static function field( $label, $value, $type, $name ) {
		return array(
			'label' => $label,
			'value' => $value,
			'type'  => $type,
			'name'  => $name,
		);
	}

	/**
	 * A string property of an object or key of an array, else "".
	 *
	 * @param mixed  $thing Object or array.
	 * @param string $key   Name.
	 * @return string
	 */
	private static function prop( $thing, $key ) {
		$value = null;
		if ( is_array( $thing ) && isset( $thing[ $key ] ) ) {
			$value = $thing[ $key ];
		} elseif ( is_object( $thing ) && isset( $thing->$key ) ) {
			$value = $thing->$key;
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A field name as a label: "your-name" → "Your name".
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function humanize( $name ) {
		$text = trim( preg_replace( '/[\s_-]+/', ' ', $name ) );
		return '' === $text ? $name : ucfirst( $text );
	}
}
