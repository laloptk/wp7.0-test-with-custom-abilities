<?php

namespace WP_Abilities_Test;

/**
 * Base class for all abilities. Subclasses define the schema and
 * execute logic; registration boilerplate lives here.
 */
abstract class Ability {

	abstract public function get_name(): string;
	abstract public function get_label(): string;
	abstract public function get_description(): string;
	abstract public function get_category(): string;
	abstract public function get_input_schema(): array;
	abstract public function get_output_schema(): array;

	/**
	 * @param mixed $input Validated input matching get_input_schema().
	 * @return mixed|\WP_Error
	 */
	abstract public function execute( $input );

	public function get_permission_callback(): callable {
		return static function () {
			return current_user_can( 'edit_posts' );
		};
	}

	public function register(): void {
		wp_register_ability(
			$this->get_name(),
			array(
				'label'               => $this->get_label(),
				'description'         => $this->get_description(),
				'category'            => $this->get_category(),
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => $this->get_permission_callback(),
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}
}
