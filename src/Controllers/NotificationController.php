<?php

namespace App\Controllers;

use App\Core\Controller;

class NotificationController extends Controller
{
    public function markAllRead(): void
    {
        $this->requireMutationRole();
        $this->requireCsrf();

        notifications()->markAllRead();
        $this->redirectBack();
    }
}
