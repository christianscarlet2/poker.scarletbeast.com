<?php

return [
    // Secret handshake a VR (Unity) client sends in the X-Scarlet-Beast-VR header so
    // its seat is treated as a HUMAN playing in VR, not a bot.
    'vr_handshake' => env('SCARLET_VR_HANDSHAKE', 'sbvr_kP9mWq2xL7tR4nZ8vF3dH6yA1cJ5gB0eUuS'),
];
