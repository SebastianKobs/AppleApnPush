<?php

declare (strict_types = 1);

/*
 * This file is part of the AppleApnPush package
 *
 * (c) Vitaliy Zhuk <zhuk2205@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code
 */

namespace Apple\ApnPush\Protocol;

use Apple\ApnPush\Exception\SendNotification\SendNotificationException;
use Apple\ApnPush\Model\Notification;
use Apple\ApnPush\Model\Receiver;

/**
 * All protocols for send notification should implement this interface
 */
interface ProtocolInterface
{
    public function addRejectListener($caller, string $callback): void;
    //
    public function addMessage(Receiver $receiver, Notification $notification, bool $sandbox = false): void;
    //
    /**
     * Send notification to receiver
     *
     * @param Receiver     $receiver
     * @param Notification $notification
     * @param bool         $sandbox
     *
     * @throws SendNotificationException
     */
    public function send(): void;

    /**
     * Close the connection
     */
    public function closeConnection(): void;
}
