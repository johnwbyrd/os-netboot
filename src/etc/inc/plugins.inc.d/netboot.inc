<?php
/*
 * Copyright (c) 2026 John Byrd <johnwbyrd@gmail.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE.
 */

/*
 * OPNsense plugin registration for os-netboot. See docs:
 *   https://docs.opnsense.org/development/backend/legacy.html
 *
 * Three services share a single configuration model:
 *   - tftpd : in.tftpd (read-only firmware-stage bootstrap)
 *   - http  : dedicated lighttpd instance (read-only, same content root,
 *             separate config + pidfile from the management webGUI lighttpd)
 *   - sftp  : dedicated sshd (optional, SFTP-only ingress)
 *
 * Each has its own rc.d script under /usr/local/etc/rc.d/netboot_*.
 */

function netboot_enabled()
{
    global $config;
    return isset($config['OPNsense']['netboot']['general']['enabled'])
        && (string)$config['OPNsense']['netboot']['general']['enabled'] === '1';
}

function netboot_sftp_enabled()
{
    global $config;
    return netboot_enabled()
        && isset($config['OPNsense']['netboot']['sftp']['enabled'])
        && (string)$config['OPNsense']['netboot']['sftp']['enabled'] === '1';
}

/*
 * Service registration. OPNsense's GUI uses this to render service status
 * indicators and start/stop/restart buttons. It does NOT, on its own, cause
 * services to start at boot -- for that, see netboot_configure() below.
 */
function netboot_services()
{
    $services = [];

    if (!netboot_enabled()) {
        return $services;
    }

    $services[] = [
        'description' => gettext('Netboot TFTP daemon'),
        'configd' => [
            'restart' => ['netboot restart_tftpd'],
            'start'   => ['netboot start_tftpd'],
            'stop'    => ['netboot stop_tftpd'],
        ],
        'name' => 'netboot_tftpd',
        'pidfile' => '/var/run/netboot_tftpd.pid',
    ];

    $services[] = [
        'description' => gettext('Netboot HTTP server'),
        'configd' => [
            'restart' => ['netboot restart_http'],
            'start'   => ['netboot start_http'],
            'stop'    => ['netboot stop_http'],
        ],
        'name' => 'netboot_http',
        'pidfile' => '/var/run/netboot_http.pid',
    ];

    if (netboot_sftp_enabled()) {
        $services[] = [
            'description' => gettext('Netboot SFTP ingress'),
            'configd' => [
                'restart' => ['netboot restart_sftp'],
                'start'   => ['netboot start_sftp'],
                'stop'    => ['netboot stop_sftp'],
            ],
            'name' => 'netboot_sftp',
            'pidfile' => '/var/run/netboot_sftp.pid',
        ];
    }

    return $services;
}

/*
 * Configure hooks. The 'bootup' event is what makes services actually start
 * at boot -- not _services() registration alone. This is the bug that affects
 * the upstream os-tftp community plugin; os-netboot does it right.
 *
 * Events:
 *   bootup    : start everything when OPNsense brings services up
 *   newwanip  : nothing (we don't bind to WAN)
 */
function netboot_configure()
{
    return [
        'bootup' => ['netboot_configure_do'],
    ];
}

function netboot_configure_do($verbose = false)
{
    if (!netboot_enabled()) {
        return;
    }

    service_log('Starting netboot services...', $verbose);
    mwexec('/usr/local/sbin/configctl netboot start_tftpd');
    mwexec('/usr/local/sbin/configctl netboot start_http');
    if (netboot_sftp_enabled()) {
        mwexec('/usr/local/sbin/configctl netboot start_sftp');
    }
    service_log("done.\n", $verbose);
}

/*
 * Firewall integration. Auto-register pass rules for the listen interfaces
 * the user picked, on the configured ports. Removes the "did you remember to
 * add a firewall rule" footgun.
 *
 * Rules are registered as filter rules with high priority so they survive
 * other interface-default rules.
 */
function netboot_firewall(\OPNsense\Firewall\Plugin $fw)
{
    global $config;

    if (!netboot_enabled()) {
        return;
    }

    $general = $config['OPNsense']['netboot']['general'] ?? [];
    $interfaces = isset($general['interfaces']) ? explode(',', (string)$general['interfaces']) : [];
    if (empty($interfaces)) {
        return;
    }

    $http_port = (int)($general['http_port'] ?? 8069);
    $sftp_port = (int)($config['OPNsense']['netboot']['sftp']['port'] ?? 2069);
    $log_default = !isset($config['syslog']['nologdefaultpass']);

    // Mirror the pattern used by core's dnsmasq.inc / kea.inc: a per-interface
    // shared base rule, with per-port specifics layered on top. Priority `1`
    // matches the convention these plugins use; the docs example shows 500000
    // but real plugins go low.
    foreach ($interfaces as $if) {
        $if = trim($if);
        if ($if === '') {
            continue;
        }

        $base = [
            'interface' => $if,
            'ipprotocol' => 'inet',
            'log' => $log_default,
            '#ref' => 'ui/netboot/general',
            'direction' => 'in',
            'to' => '(self)',
        ];

        $fw->registerFilterRule(1, array_merge($base, [
            'protocol' => 'udp',
            'to_port' => 69,
            'descr' => 'os-netboot: allow access to TFTP server',
        ]));

        $fw->registerFilterRule(1, array_merge($base, [
            'protocol' => 'tcp',
            'to_port' => $http_port,
            'descr' => sprintf('os-netboot: allow access to HTTP server (port %d)', $http_port),
        ]));

        if (netboot_sftp_enabled()) {
            $fw->registerFilterRule(1, array_merge($base, [
                'protocol' => 'tcp',
                'to_port' => $sftp_port,
                'descr' => sprintf('os-netboot: allow access to SFTP server (port %d)', $sftp_port),
            ]));
        }
    }
}

/*
 * XMLRPC HA sync. Lets a HA peer pull the netboot config section so both
 * firewalls in an HA pair serve netboot identically.
 */
function netboot_xmlrpc_sync()
{
    return [[
        'description' => gettext('Netboot'),
        'section'     => 'OPNsense.netboot',
        'id'          => 'netboot',
        'services'    => 'netboot_tftpd,netboot_http,netboot_sftp',
    ]];
}
