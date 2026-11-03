<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Shared SIP peer option lists for Trunks and SIP Users.
 * Labels keep familiar Asterisk names; values map to OpenSIPS-backed behaviour where applicable.
 */
final class SipPeerOptions
{
    public const YES_NO = ['yes' => 'Yes', 'no' => 'No'];

    public const DTMF = [
        'RFC2833' => 'RFC2833 / telephone-event',
        'info' => 'SIP INFO',
        'inband' => 'Inband',
        'auto' => 'Auto',
    ];

    public const PEER_TYPE = [
        'friend' => 'friend (register + call)',
        'peer' => 'peer (IP / outbound)',
        'user' => 'user (register only)',
    ];

    public const PROVIDER_TECH = [
        'sip' => 'sip',
        'iax2' => 'iax2',
    ];

    /** OpenSIPS: force_rport / received via nathelper; comedia ≈ symmetric RTP (relay). */
    public const NAT = [
        'no' => 'No',
        'force_rport' => 'force_rport',
        'comedia' => 'comedia',
        'force_rport,comedia' => 'force_rport,comedia',
    ];

    /** Trust remote port / skip digest on INVITE when IP is in address table. */
    public const INSECURE = [
        'no' => 'no',
        'port' => 'port',
        'invite' => 'invite',
        'port,invite' => 'port,invite',
    ];

    public const CODECS = [
        'PCMU,PCMA' => 'G.711 μ-law + A-law',
        'PCMU,PCMA,G729' => 'G.711 + G.729',
        'PCMU,PCMA,opus' => 'G.711 + Opus',
        'PCMU,PCMA,G729,opus' => 'G.711 + G.729 + Opus',
        'opus,PCMU,PCMA' => 'Opus preferred',
        'G729' => 'G.729 only',
    ];
}
