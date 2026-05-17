<?php

namespace WP_Abilities_Test;

/**
 * Holds a collection of Ability instances. Add abilities here
 * to make them available for registration and execution.
 */
class Ability_Factory {

	/** @var Ability[] */
	private array $abilities = array();

	public function add( Ability $ability ): static {
		$this->abilities[ $ability->get_name() ] = $ability;
		return $this;
	}

	public function get( string $name ): ?Ability {
		return $this->abilities[ $name ] ?? null;
	}

	/** @return Ability[] */
	public function all(): array {
		return $this->abilities;
	}
}
