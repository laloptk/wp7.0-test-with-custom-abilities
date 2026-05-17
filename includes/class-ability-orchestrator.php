<?php

namespace WP_Abilities_Test;

/**
 * Wires abilities into WordPress. Add abilities here, then call
 * register_all() once to hook them all into wp_abilities_api_init.
 */
class Ability_Orchestrator {

	private Ability_Factory $factory;

	public function __construct() {
		$this->factory = new Ability_Factory();
	}

	public function add( Ability $ability ): static {
		$this->factory->add( $ability );
		return $this;
	}

	public function register_all(): void {
		add_action(
			'wp_abilities_api_categories_init',
			array( $this, 'register_categories' )
		);

		add_action(
			'wp_abilities_api_init',
			function () {
				foreach ( $this->factory->all() as $ability ) {
					$ability->register();
				}
			}
		);
	}

	public function register_categories(): void {
		wp_register_ability_category(
			'content',
			array(
				'label'       => __( 'Content', 'wp-abilities-api-test' ),
				'description' => __( 'Abilities for creating and managing post content.', 'wp-abilities-api-test' ),
			)
		);
	}

	/**
	 * Execute an ability by name, delegating to WP's registry.
	 *
	 * @param mixed $input
	 * @return mixed|\WP_Error
	 */
	public function execute( string $ability_name, $input = null ) {
		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return new \WP_Error(
				'ability_not_found',
				sprintf( 'Ability "%s" is not registered.', $ability_name )
			);
		}

		return $ability->execute( $input );
	}
}
