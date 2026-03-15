{**
 * Dashboard de Taquillas de Recogida
 *}

<div class="panel">
    <h3><i class="icon-dashboard"></i> Dashboard de Taquillas</h3>
    
    {* Stats Cards *}
    <div class="row" style="margin-bottom: 20px;">
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="panel" style="background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; text-align: center; padding: 15px;">
                <h1 style="margin: 0; font-size: 36px;">{$stats.available}</h1>
                <p style="margin: 5px 0 0 0; font-size: 12px;"><i class="icon-unlock"></i> Disponibles</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="panel" style="background: linear-gradient(135deg, #e67e22, #d35400); color: white; text-align: center; padding: 15px;">
                <h1 style="margin: 0; font-size: 36px;">{$stats.pending_pickup}</h1>
                <p style="margin: 5px 0 0 0; font-size: 12px;"><i class="icon-inbox"></i> Por recoger</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="panel" style="background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; text-align: center; padding: 15px;">
                <h1 style="margin: 0; font-size: 36px;">{$stats.pending_refill}</h1>
                <p style="margin: 5px 0 0 0; font-size: 12px;"><i class="icon-refresh"></i> Pend. rellenar</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="panel" style="background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; text-align: center; padding: 15px;">
                <h1 style="margin: 0; font-size: 36px;">{$stats.waiting}</h1>
                <p style="margin: 5px 0 0 0; font-size: 12px;"><i class="icon-time"></i> En espera</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="panel" style="background: linear-gradient(135deg, #1abc9c, #16a085); color: white; text-align: center; padding: 15px;">
                <h1 style="margin: 0; font-size: 36px;">{$stats.picked_today}</h1>
                <p style="margin: 5px 0 0 0; font-size: 12px;"><i class="icon-check"></i> Recogidos hoy</p>
            </div>
        </div>
        {if $stats.expired_grace > 0}
        <div class="col-lg-2 col-md-4 col-sm-6">
            <div class="panel" style="background: linear-gradient(135deg, #f39c12, #d68910); color: white; text-align: center; padding: 15px;">
                <h1 style="margin: 0; font-size: 36px;">{$stats.expired_grace}</h1>
                <p style="margin: 5px 0 0 0; font-size: 12px;"><i class="icon-clock-o"></i> Plazo expirado</p>
            </div>
        </div>
        {/if}
    </div>

    {* Estado de Taquillas *}
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-inbox"></i> Estado de Taquillas
        </div>
        <div class="row">
            {foreach from=$lockers item=locker}
                <div class="col-lg-3 col-md-4 col-sm-6" style="margin-bottom: 15px;">
                    <div class="panel" style="margin: 0; border-left: 4px solid 
                        {if $locker.status == 'available'}#2ecc71
                        {elseif $locker.status == 'pending_refill'}#9b59b6
                        {elseif $locker.status == 'maintenance'}#e74c3c
                        {else}#e67e22{/if};">
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0;">
                                <i class="icon-inbox"></i> {$locker.name}
                            </h4>
                            <span class="label 
                                {if $locker.status == 'available'}label-success
                                {elseif $locker.status == 'pending_refill'}label-info
                                {elseif $locker.status == 'maintenance'}label-danger
                                {else}label-warning{/if}">
                                {if $locker.status == 'available'}Libre
                                {elseif $locker.status == 'pending_refill'}Pendiente rellenar
                                {elseif $locker.status == 'maintenance'}Mantenimiento
                                {elseif $locker.status == 'assigned' || $locker.status == 'occupied'}Ocupada
                                {else}{$locker.status}{/if}
                            </span>
                        </div>
                        
                        {* Operator PIN - always show if exists *}
                        {if $locker.operator_pin}
                            <div style="background: #f0f0f0; padding: 10px; border-radius: 5px; margin-bottom: 10px; text-align: center;">
                                <small style="color: #666;">PIN Operario:</small><br>
                                <code style="font-size: 20px; color: #9b59b6;">{$locker.operator_pin}</code>
                                <br><small style="color: #999;">Válido hasta: {$locker.operator_pin_valid_until}</small>
                            </div>
                        {/if}

                        {if $locker.status == 'pending_refill'}
                            <p style="color: #9b59b6; font-size: 12px; margin-bottom: 10px;">
                                <i class="icon-info-circle"></i> Cliente ya recogió. Añade un nuevo llavero.
                            </p>
                            <a href="{$current_url}&generateOperatorPin=1&id_locker={$locker.id_locker}" 
                               class="btn btn-info btn-block" style="margin-bottom: 5px;">
                                <i class="icon-key"></i> Generar PIN para abrir
                            </a>
                            <a href="{$current_url}&markAvailable=1&id_locker={$locker.id_locker}" 
                               class="btn btn-success btn-block"
                               onclick="return confirm('¿Has añadido un nuevo llavero a esta taquilla?');">
                                <i class="icon-plus-circle"></i> Llavero añadido
                            </a>
                        {elseif $locker.current_order_id}
                            <p style="font-size: 12px; color: #666;">
                                <strong>Pedido:</strong> 
                                <a href="{$order_link_base}&id_order={$locker.current_order_id}&vieworder" target="_blank">
                                    #{$locker.current_order_ref}
                                </a><br>
                                <strong>Cliente:</strong> {$locker.current_customer}<br>
                                <strong>PIN:</strong> <code>{$locker.current_pin}</code><br>
                                <strong>Válido hasta:</strong> {$locker.current_valid_until|date_format:"%d/%m/%Y %H:%M"}
                            </p>
                            <a href="{$current_url}&releaseLocker=1&id_locker={$locker.id_locker}" 
                               class="btn btn-warning btn-sm"
                               onclick="return confirm('¿Liberar esta taquilla? Se cancelará la asignación actual.');">
                                <i class="icon-times"></i> Liberar
                            </a>
                        {elseif $locker.status == 'available'}
                            <p style="color: #2ecc71; font-size: 12px; margin-bottom: 10px;">
                                <i class="icon-check"></i> Lista para asignar
                            </p>
                            <a href="{$current_url}&generateOperatorPin=1&id_locker={$locker.id_locker}" 
                               class="btn btn-info btn-sm" style="margin-bottom: 5px;">
                                <i class="icon-key"></i> PIN Operario
                            </a>
                            <a href="{$current_url}&setMaintenance=1&id_locker={$locker.id_locker}" 
                               class="btn btn-warning btn-sm">
                                <i class="icon-wrench"></i> Mantenimiento
                            </a>
                        {elseif $locker.status == 'maintenance'}
                            <p style="color: #e74c3c; font-size: 12px; margin-bottom: 10px;">
                                <i class="icon-wrench"></i> En mantenimiento
                            </p>
                            <a href="{$current_url}&generateOperatorPin=1&id_locker={$locker.id_locker}" 
                               class="btn btn-info btn-sm" style="margin-bottom: 5px;">
                                <i class="icon-key"></i> PIN Operario
                            </a>
                            <a href="{$current_url}&markAvailable=1&id_locker={$locker.id_locker}" 
                               class="btn btn-success btn-sm">
                                <i class="icon-check"></i> Fin mantenimiento
                            </a>
                        {/if}
                    </div>
                </div>
            {/foreach}
        </div>
    </div>

    {* Pedidos en Espera (sin taquilla) *}
    {if $waiting_assignments|count > 0}
    <div class="panel" style="border-left: 4px solid #e74c3c;">
        <div class="panel-heading" style="background: #fdecea;">
            <i class="icon-warning-sign"></i> <strong style="color: #e74c3c;">Pedidos en Cola de Espera ({$waiting_assignments|count})</strong>
            <span style="float: right; font-size: 12px; color: #666;">Se asignarán automáticamente cuando haya taquillas disponibles</span>
        </div>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Pedido</th>
                    <th>Cliente</th>
                    <th>Email</th>
                    <th>En espera desde</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$waiting_assignments item=assignment name=waiting}
                    <tr>
                        <td><span class="badge" style="background: #e74c3c;">{$smarty.foreach.waiting.iteration}</span></td>
                        <td>
                            <a href="{$order_link_base}&id_order={$assignment.id_order}&vieworder" target="_blank">
                                #{$assignment.order_reference}
                            </a>
                        </td>
                        <td>{$assignment.customer_name}</td>
                        <td><small>{$assignment.customer_email}</small></td>
                        <td>{$assignment.date_add|date_format:"%d/%m/%Y %H:%M"}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    {/if}

    {* Asignaciones Activas *}
    {if $active_assignments|count > 0}
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-time"></i> Pendientes de Recogida ({$active_assignments|count})
        </div>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Cliente</th>
                    <th>Taquilla</th>
                    <th>PIN</th>
                    <th>Válido hasta</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$active_assignments item=assignment}
                    <tr>
                        <td>
                            <a href="{$order_link_base}&id_order={$assignment.id_order}&vieworder" target="_blank">
                                #{$assignment.order_reference}
                            </a>
                        </td>
                        <td>{$assignment.customer_name}<br><small>{$assignment.customer_email}</small></td>
                        <td>{$assignment.locker_name}</td>
                        <td><code style="font-size: 14px;">{$assignment.pin_code}</code></td>
                        <td>{$assignment.valid_until|date_format:"%d/%m/%Y %H:%M"}</td>
                        <td>
                            {if $assignment.status == 'ready'}
                                <span class="label label-success">Listo</span>
                            {elseif $assignment.status == 'expired_grace'}
                                <span class="label label-warning">⏰ Expirado (gracia)</span>
                            {else}
                                <span class="label label-info">{$assignment.status}</span>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    {/if}

    {* Historial Unificado *}
    {if $unified_history|count > 0}
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-history"></i> Historial de Operaciones
        </div>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Acción</th>
                    <th>Descripción</th>
                    <th>Pedido</th>
                    <th>Taquilla</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$unified_history item=entry}
                    <tr>
                        <td>{$entry.fecha|date_format:"%d/%m/%Y %H:%M"}</td>
                        <td>
                            {if $entry.action == 'assigned'}
                                <span class="label label-success">Asignado</span>
                            {elseif $entry.action == 'assigned_from_queue'}
                                <span class="label label-info">Asignado (cola)</span>
                            {elseif $entry.action == 'waiting'}
                                <span class="label label-warning">En espera</span>
                            {elseif $entry.action == 'expired'}
                                <span class="label label-danger">Expirado</span>
                            {elseif $entry.action == 'picked_up'}
                                <span class="label label-success">Recogido</span>
                            {elseif $entry.action == 'cancelled'}
                                <span class="label label-danger">Cancelado</span>
                            {else}
                                <span class="label label-default">{$entry.action}</span>
                            {/if}
                        </td>
                        <td>{$entry.description}</td>
                        <td>
                            {if $entry.order_reference}
                                <a href="{$order_link_base}&id_order={$entry.id_order}&vieworder" target="_blank">
                                    #{$entry.order_reference}
                                </a>
                            {else}
                                -
                            {/if}
                        </td>
                        <td>{$entry.locker_name|default:'-'}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    {/if}
</div>
