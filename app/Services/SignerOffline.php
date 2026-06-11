<?php

namespace App\Services;

/** Raised when the house signer is in cold custody and cannot broadcast. */
class SignerOffline extends \RuntimeException
{
}
