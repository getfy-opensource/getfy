<?php

/**
 * Limites de upload do Member Builder (área de membros).
 * Valores em kilobytes (regra Laravel `max:` em ficheiros).
 *
 * O servidor também precisa de permitir o mesmo ou mais:
 * - PHP: upload_max_filesize, post_max_size
 * - Nginx: client_max_body_size
 * - Cloudflare: limite de body (normalmente suficiente para dezenas de MB)
 */
return [
    /** Imagens gerais (hero, logos, capas de módulo, etc.) — rota upload */
    'image_max_kb' => (int) env('MEMBER_BUILDER_UPLOAD_IMAGE_MAX_KB', 10240),

    /** Imagens de badges / gamificação — rota upload-badge */
    'badge_image_max_kb' => (int) env('MEMBER_BUILDER_UPLOAD_BADGE_IMAGE_MAX_KB', 5120),

    /** PDFs de material e apresentação — rota upload-pdf */
    'pdf_max_kb' => (int) env('MEMBER_BUILDER_UPLOAD_PDF_MAX_KB', 51200),
];
