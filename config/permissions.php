<?php

/*
|--------------------------------------------------------------------------
| Catálogo de permisos del CRM
|--------------------------------------------------------------------------
|
| Cada grupo contiene permisos con la forma "grupo.accion". Para sumar un
| permiso nuevo basta agregarlo aquí: aparece solo en la matriz de roles y
| queda disponible como Gate ("can:clave"), en las policies y en el front
| mediante can('clave').
|
| El rol con slug "admin" siempre tiene todos los permisos.
|
*/

return [
    'admin_role' => 'admin',

    'groups' => [
        'dashboard' => [
            'label' => 'Dashboard y Kanban',
            'icon' => 'layout-dashboard',
            'permissions' => [
                'dashboard.view' => 'Ver dashboard y tablero',
            ],
        ],
        'leads' => [
            'label' => 'Prospectos',
            'icon' => 'users',
            'permissions' => [
                'leads.view' => 'Ver prospectos asignados a él/ella',
                'leads.view_all' => 'Ver todos los prospectos',
                'leads.create' => 'Crear prospectos',
                'leads.update' => 'Editar prospectos',
                'leads.move' => 'Mover entre etapas',
                'leads.assign' => 'Asignar a otros usuarios',
                'leads.delete' => 'Eliminar prospectos',
                'leads.notes' => 'Agregar notas y seguimientos',
            ],
        ],
        'real_estate' => [
            'label' => 'Proyectos inmobiliarios',
            'icon' => 'building',
            'permissions' => [
                'projects.view' => 'Ver los proyectos donde es ejecutivo/a',
                'projects.view_all' => 'Ver todos los proyectos',
                'projects.manage' => 'Crear y editar proyectos y asignar ejecutivos',
                'units.view' => 'Ver inventario de unidades y precios',
                'units.manage' => 'Crear, editar e importar unidades, precios y plantas',
                'units.status' => 'Bloquear o liberar unidades',
            ],
        ],
        'quotes' => [
            'label' => 'Cotizaciones',
            'icon' => 'calculator',
            'permissions' => [
                'quotes.view' => 'Ver sus cotizaciones',
                'quotes.view_all' => 'Ver todas las cotizaciones',
                'quotes.create' => 'Crear y editar cotizaciones',
                'quotes.send' => 'Enviar cotizaciones y cambiar su estado',
                'quotes.discount' => 'Aplicar descuentos sobre el máximo del proyecto',
                'quotes.delete' => 'Eliminar cotizaciones',
            ],
        ],
        'reservations' => [
            'label' => 'Reservas y pagos',
            'icon' => 'key-round',
            'permissions' => [
                'reservations.view' => 'Ver sus reservas',
                'reservations.view_all' => 'Ver todas las reservas',
                'reservations.create' => 'Reservar unidades',
                'reservations.manage' => 'Promesar, escriturar o desistir reservas y editar el plan de pagos',
                'reservations.payments' => 'Registrar pagos',
            ],
        ],
        'clients' => [
            'label' => 'Clientes',
            'icon' => 'building-2',
            'permissions' => [
                'clients.view' => 'Ver clientes',
                'clients.create' => 'Crear clientes',
                'clients.update' => 'Editar clientes',
                'clients.delete' => 'Eliminar clientes',
            ],
        ],
        'proposals' => [
            'label' => 'Propuestas comerciales',
            'icon' => 'file-signature',
            'permissions' => [
                'proposals.view' => 'Ver propuestas',
                'proposals.create' => 'Crear y editar propuestas',
                'proposals.send' => 'Enviar y cambiar el estado de propuestas',
                'proposals.delete' => 'Eliminar propuestas',
                'services.view' => 'Ver el catálogo de servicios',
                'services.manage' => 'Administrar el catálogo de servicios y tarifas',
                'agency.manage' => 'Editar los datos de mi empresa (firma y pie de propuestas)',
            ],
        ],
        'contracts' => [
            'label' => 'Servicios',
            'icon' => 'package-check',
            'permissions' => [
                'contracts.view' => 'Ver servicios contratados',
                'contracts.create' => 'Crear servicios contratados',
                'contracts.update' => 'Editar servicios contratados',
                'contracts.delete' => 'Eliminar servicios contratados',
                'contracts.costs' => 'Ver y registrar costos y gastos (información interna)',
            ],
        ],
        'billing' => [
            'label' => 'Facturación y cobranza',
            'icon' => 'receipt',
            'permissions' => [
                'billing.view' => 'Ver cobros y facturas',
                'billing.manage' => 'Crear cobros, adjuntar facturas y enviar cobros y recordatorios',
                'billing.mark_paid' => 'Marcar facturas como pagadas',
                'billing.delete' => 'Eliminar o anular cobros',
                'billing.settings' => 'Configurar los recordatorios de pago',
                'portal.manage' => 'Crear y administrar accesos al portal de clientes',
            ],
        ],
        'users' => [
            'label' => 'Usuarios',
            'icon' => 'user-cog',
            'permissions' => [
                'users.view' => 'Ver usuarios',
                'users.create' => 'Crear usuarios',
                'users.update' => 'Editar usuarios',
                'users.delete' => 'Eliminar usuarios',
            ],
        ],
        'roles' => [
            'label' => 'Roles y permisos',
            'icon' => 'shield-check',
            'permissions' => [
                'roles.view' => 'Ver roles',
                'roles.create' => 'Crear roles',
                'roles.update' => 'Editar roles y permisos',
                'roles.delete' => 'Eliminar roles',
            ],
        ],
        'email' => [
            'label' => 'Email marketing',
            'icon' => 'mail',
            'permissions' => [
                'templates.view' => 'Ver plantillas de email',
                'templates.manage' => 'Crear y editar plantillas',
                'campaigns.view' => 'Ver boletines y su rendimiento',
                'campaigns.create' => 'Crear y programar boletines',
                'campaigns.send' => 'Enviar boletines',
                'lists.manage' => 'Gestionar audiencias (listas)',
                'automations.manage' => 'Gestionar automatizaciones',
                'email_logs.view' => 'Ver historial de mensajes',
                'email_settings.manage' => 'Configurar Resend, API keys y bajas',
            ],
        ],
        'ai' => [
            'label' => 'Inteligencia artificial',
            'icon' => 'sparkles',
            'permissions' => [
                'ai.use' => 'Usar el asistente de IA (mailings, análisis de clientes y lectura de facturas)',
                'ai.manage' => 'Configurar proveedores de IA y API keys',
                'agent.use' => 'Usar el Agent (asistente que opera el CRM por chat)',
                'mcp.manage' => 'Configurar servidores MCP (herramientas externas de la IA, API keys y OAuth)',
                'mcp.use' => 'Permitir que el Agent use herramientas MCP externas en nombre del usuario',
            ],
        ],
        'settings' => [
            'label' => 'Configuración del CRM',
            'icon' => 'settings-2',
            'permissions' => [
                'sources.manage' => 'Gestionar orígenes y API',
                'stages.manage' => 'Gestionar etapas del Kanban',
                'fields.manage' => 'Gestionar campos personalizados',
                'brand.manage' => 'Cambiar la identidad visual (colores, tipografías e imagen hero)',
                'trash.manage' => 'Ver la papelera y restaurar datos eliminados',
                'demo.purge' => 'Limpiar datos de prueba (borrado definitivo de prospectos, clientes y propuestas)',
            ],
        ],
    ],

    /*
    | Roles creados por el seeder. "admin" es de sistema (no editable ni
    | eliminable). Los demás se pueden modificar libremente desde el CRM.
    */
    'default_roles' => [
        'admin' => [
            'name' => 'Administrador',
            'description' => 'Acceso total al CRM.',
            'is_system' => true,
            'permissions' => ['*'],
        ],
        'comercial' => [
            'name' => 'Ejecutivo comercial',
            'description' => 'Gestiona sus prospectos, cotiza y reserva unidades de sus proyectos.',
            'is_system' => false,
            'permissions' => [
                'dashboard.view',
                'leads.view', 'leads.create', 'leads.update', 'leads.move', 'leads.notes',
                'clients.view', 'clients.create', 'ai.use', 'agent.use',
                'projects.view', 'units.view', 'quotes.view', 'quotes.create', 'quotes.send', 'reservations.view', 'reservations.create', 'reservations.payments',
            ],
        ],
        'supervisor' => [
            'name' => 'Supervisor',
            'description' => 'Jefatura de ventas: ve todos los proyectos, administra inventario, cotizaciones y reservas.',
            'is_system' => false,
            'permissions' => [
                'dashboard.view',
                'leads.view', 'leads.view_all', 'leads.create', 'leads.update', 'leads.move', 'leads.assign', 'leads.notes',
                'clients.view', 'clients.create', 'clients.update',
                'projects.view', 'projects.view_all', 'projects.manage', 'units.view', 'units.manage', 'units.status',
                'quotes.view', 'quotes.view_all', 'quotes.create', 'quotes.send', 'quotes.discount', 'quotes.delete',
                'reservations.view', 'reservations.view_all', 'reservations.create', 'reservations.manage', 'reservations.payments',
                'proposals.view', 'proposals.create', 'proposals.send', 'proposals.delete', 'services.view', 'services.manage', 'agency.manage',
                'contracts.view', 'contracts.create', 'contracts.update', 'billing.view', 'billing.manage', 'billing.mark_paid', 'portal.manage',
                'users.view',
                'templates.view', 'campaigns.view', 'email_logs.view', 'ai.use', 'agent.use',
            ],
        ],
        'lectura' => [
            'name' => 'Solo lectura',
            'description' => 'Consulta información sin modificarla.',
            'is_system' => false,
            'permissions' => ['dashboard.view', 'leads.view', 'leads.view_all', 'clients.view', 'projects.view', 'projects.view_all', 'units.view', 'quotes.view', 'quotes.view_all', 'reservations.view', 'reservations.view_all'],
        ],
    ],
];
