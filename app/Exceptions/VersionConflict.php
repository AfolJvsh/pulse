<?php
namespace App\Exceptions;use RuntimeException;
final class VersionConflict extends RuntimeException{public function __construct(public readonly array $latest){parent::__construct('The incident changed since this client snapshot.');}}
