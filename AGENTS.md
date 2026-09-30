# Instrucciones del proyecto

## Regla global de acceso al backoffice

- El rol `admin` tiene acceso total al backoffice mediante el bypass global de administrador.
- Cualquier usuario que no sea administrador necesita al menos un permiso real y válido definido en `config/admin_permissions.php` para acceder al panel Filament. La comprobación central es `app_user_has_any_admin_permission()`, expuesta para el acceso general mediante `app_user_can_access_admin_panel()`.
- Ese permiso puede estar concedido directamente al usuario o mediante su rol adicional. Los permisos inexistentes, obsoletos o ajenos al catálogo central no conceden acceso.
- Sin permisos válidos no se muestra la opción “Admin” en la navbar, no se puede acceder al panel Filament ni se permite el acceso mediante una URL directa.
- Tener acceso al panel no concede acceso a todos los módulos. Cada recurso o página debe seguir protegiendo su navegación, sus URLs y sus acciones con su permiso individual.
- Toda nueva funcionalidad del backoffice debe registrar su permiso en la configuración central, reutilizar la comprobación individual para navegación y autorización, quedar cubierta por tests y mantener esta regla global de acceso al panel.

### Visor de roles

- El visor de roles se gestiona mediante el permiso `roles.view` desde el panel de Permisos, sin concesiones predeterminadas para perfiles no administradores.
- La visibilidad del visor y el contenido que puede consultar son controles distintos: el permiso habilita la funcionalidad y el visor solo expone roles adicionales.
- El permiso `roles.view` es exclusivo del visor y no cuenta para el acceso global al backoffice. La opción Admin y el acceso al panel solo se habilitan mediante permisos reales de módulos del backoffice.
- Nunca se debe confiar únicamente en ocultar el elemento visual: las rutas y peticiones del visor deben validar el permiso y los roles permitidos en servidor.
- Toda nueva consulta de roles debe respetar esta jerarquía, los grants directos y heredados, las revocaciones y el bypass de Admin.
- El visor de roles de la navbar permite a un administrador simular tanto roles base como roles adicionales. Cuando hay una selección activa, esta sustituye completamente la identidad funcional para la aplicación: se usa únicamente el rol simulado, sin `extra_role`, permisos directos ni grants heredados de la identidad real. Al desactivarlo se restaura la combinación real del usuario.

### Tickets IT

- La pestaña principal de Tickets IT continúa siendo una funcionalidad innata del rol adicional `informatica`, mediante `app_can_access_tickets()`; no debe convertirse en un permiso configurable.
- `tickets-it.assign` controla exclusivamente la asignación de tickets y sus acciones servidor; `tickets-it.reports.view` controla exclusivamente la navegación y consulta de informes de Tickets IT.
- Ambos permisos son de aplicación, no cuentan para el acceso global al backoffice y no deben hacer aparecer la opción Admin.
- `roles.view`, `tickets-it.assign` y `tickets-it.reports.view` están excluidos explícitamente del catálogo que habilita Filament, aunque sus grants existan y estén activos.
- `ticket-tools.manage` conserva su clave y autorización de backoffice, pero se agrupa visualmente dentro del apartado `Tickets IT` del panel de permisos.
- Estos permisos se gestionan desde el panel de Permisos, respetan grants directos, grants heredados por `group_role`, revocaciones y el bypass de Admin.
- `backoffice.tickets-it-recipients.manage` es un permiso de backoffice independiente para gestionar la configuración de destinatarios de avisos de Tickets IT. Cuenta para el acceso global al backoffice, pero no concede `tickets-it.assign`, `tickets-it.reports.view` ni acceso funcional a Tickets IT.
- `chat-retention-holds.manage` controla la navegación, acceso y acciones de Conservación excepcional de conversación del chat. No debe limitarse al rol Admin: se respetan grants directos, heredados, revocaciones y el bypass de Admin.
- `conversation-access.manage` controla la navegación, acceso, solicitudes justificadas, exportaciones y auditoría de Acceso justificado a conversaciones. No debe limitarse al rol Admin: se respetan grants directos, heredados, revocaciones y el bypass de Admin.
- `backoffice.rankings.manage` controla exclusivamente la navegación, acceso y recarga de Rankings en Filament. Cuenta para el acceso global al backoffice, pero no concede ningún otro módulo.
- `rankings.view` controla exclusivamente la visibilidad y el acceso a los rankings públicos de la aplicación. Es un permiso de aplicación, no concede acceso al backoffice ni permite recargar datos desde Salesforce. Es independiente de `backoffice.rankings.manage`.
- `videos.view` controla exclusivamente la visibilidad y el acceso a Vídeos de formación en la aplicación. Es un permiso de aplicación, no concede acceso al backoffice ni hace aparecer la opción Admin. Los grants iniciales para comercial, responsable de tienda y responsable de zona sustituyen la condición legacy basada en `extra_role`.
- `reports.hr.view` controla únicamente la visibilidad y el acceso a los informes HR de la aplicación. Es un permiso de aplicación, no concede acceso al backoffice ni debe considerarse un permiso administrativo. Los grants iniciales para gerencia y área manager sustituyen la condición legacy basada en roles.
- `reviews.view` controla únicamente la visibilidad y el acceso a la funcionalidad de reseñas de la aplicación. Es un permiso de aplicación, no concede acceso al backoffice ni debe considerarse un permiso administrativo. Los grants iniciales para marketing y gerencia sustituyen la condición legacy basada en roles.
- `reviews.google.manage` controla exclusivamente la conexión OAuth de Google Reviews desde el backoffice. Es un permiso de backoffice; la página pública de Reseñas no debe mostrar acciones de conexión y ninguna ruta OAuth debe depender de `role` o `extra_role`.
- `curricula.view` controla únicamente la visibilidad y el acceso a la funcionalidad de currículums de la aplicación. Es un permiso de aplicación, no concede acceso al backoffice ni debe considerarse un permiso administrativo. El grant inicial para Recursos Humanos sustituye la condición legacy basada en `extra_role`.
- El permiso legacy de gestión de Tickets IT no existe en el catálogo ni en los grants activos. Todas las vistas y acciones de Tickets IT deben proteger servidor y navegación con `tickets-it.assign` o `tickets-it.reports.view`.
- La visibilidad básica por `extra_role = informatica` no sustituye a los permisos de gestión. `tickets-it.assign` debe habilitar la pestaña, la tabla completa y la asignación para cualquier usuario autorizado, aunque no pertenezca a Informática; la autorización servidor debe usar el mismo permiso.
