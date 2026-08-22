<?php

declare(strict_types=1);

/**
 * Whether this run may deliver to real addresses.
 *
 * Two gates, and the second is the one worth explaining.
 *
 * SPARKPOST_DELIVER=1 is the deliberate act: without it everything goes to SparkPost's
 * sink. That protects a session which does not know a populated .env is sitting here.
 *
 * It does NOT protect against a .env that already says SPARKPOST_DELIVER=1 - which is the
 * normal state of this working copy, because that is how Simon runs it. A session that
 * knows about the flag, and believes the default is sink, then sends real mail. That has
 * happened: four real messages on 22 August 2026, from a run described as a sink run.
 *
 * So delivery is refused outright when the run is an agent's. Claude Code sets CLAUDECODE
 * in every shell it opens, which is a fact about the runner rather than about the
 * environment file, and is therefore the one signal a stale .env cannot fake. A session
 * that has been asked to send for real overrides it explicitly, and the override is
 * deliberately not something anyone types by habit.
 *
 * It fails safe: if CLAUDECODE ever disappears or is renamed, this returns to plain
 * SPARKPOST_DELIVER behaviour, which is still sink unless asked otherwise.
 *
 * @return array{0: bool, 1: string}  whether to deliver, and how to describe the decision
 */
function rig_delivery(): array
{
    if (getenv('SPARKPOST_DELIVER') !== '1') {
        return [false, 'sink - nothing will be delivered'];
    }

    if (getenv('CLAUDECODE') !== false && getenv('SPARKPOST_AGENT_MAY_DELIVER') !== '1') {
        return [false, 'sink - SPARKPOST_DELIVER ignored in an agent session'];
    }

    return [true, 'DELIVER - this reaches real addresses'];
}
