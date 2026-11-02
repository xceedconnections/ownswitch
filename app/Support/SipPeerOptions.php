<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/** Shared Magnus-style SIP peer option lists for Trunks and SIP Users. */
final class SipPeerOptions
{
    public const YES_NO = ['yes' => 'Yes', 'no' => 'No'];

    public const DTMF = [
        'RFC2833' => 'RFC2833',
        'info' => 'SIP INFO',
        'inband' => 'Inband',
        'auto' => 'Auto',
    ];

    public const PEER_TYPE = [
        'friend' => 'friend',
        'peer' => 'peer',
        'user' => 'user',
    ];

    public const PROVIDER_TECH = [
        'sip' => 'sip',
        'iax2' => 'iax2',
    ];
}
