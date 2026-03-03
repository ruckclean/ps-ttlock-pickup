{**
 * Dashboard de taquillas con estado visual
 *}

{* Dashboard de estadísticas *}
<div class="row">
    <div class="col-lg-3 col-md-6">
        <div class="panel" style="background: linear-gradient(135deg, #00D4AA, #00B894); color: white;">
            <div class="panel-body text-center">
                <h1 style="font-size: 48px; margin: 0;">{$stats.available|default:0}</h1>
                <p style="margin: 0; font-size: 16px;"><i class="icon-unlock"></i> Disponibles</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="panel" style="background: linear-gradient(135deg, #FDCB6E, #E17055); color: white;">
            <div class="panel-body text-center">
                <h1 style="font-size: 48px; margin: 0;">{$stats.occupied|default:0}</h1>
                <p style="margin: 0; font-size: 16px;"><i class="icon-lock"></i> Ocupadas</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="panel" style="background: linear-gradient(135deg, #74B9FF, #0984E3); color: white;">
            <div class="panel-body text-center">
                <h1 style="font-size: 48px; margin: 0;">{$stats.pending|default:0}</h1>
                <p style="margin: 0; font-size: 16px;"><i class="icon-time"></i> Pendientes</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="panel" style="background: linear-gradient(135deg, #A29BFE, #6C5CE7); color: white;">
            <div class="panel-body text-center">
                <h1 style="font-size: 48px; margin: 0;">{$stats.picked_today|default:0}</h1>
                <p style="margin: 0; font-size: 16px;"><i class="icon-check"></i> Recogidos hoy</p>
            </div>
        </div>
    </div>
</div>

{* Tarjetas visuales de cada taquilla *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-th-large"></i> {l s='Estado de Taquillas' mod='rkpickup'}
    </div>
    <div class="panel-body">
        <div class="row">
            {if $lockers && count($lockers) > 0}
                {foreach from=$lockers item=locker}
                    <div class="col-lg-3 col-md-4 col-sm-6">
                        <div class="panel {if $locker.status == 'available'}panel-success{elseif $locker.status == 'occupied'}panel-warning{else}panel-danger{/if}" style="margin-bottom: 15px;">
                            <div class="panel-heading" style="padding: 10px 15px;">
                                <i class="icon-{if $locker.status == 'available'}unlock{else}lock{/if}"></i>
                                <strong>{$locker.name}</strong>
                                <span class="pull-right badge">
                                    {if $locker.status == 'available'}Libre{elseif $locker.status == 'occupied'}Ocupada{else}Manten.{/if}
                                </span>
                            </div>
                            <div class="panel-body" style="min-height: 100px; padding: 10px 15px;">
                                {if $locker.description}
                                    <p class="text-muted" style="margin-bottom: 5px;"><small>{$locker.description}</small></p>
                                {/if}
                                
                                {if $locker.status == 'occupied' && $locker.current_order_id}
                                    <p style="margin-bottom: 5px;">
                                        <strong>Pedido:</strong> 
                                        <a href="{$order_link_base}&id_order={$locker.current_order_id}&vieworder" target="_blank">
                                            #{$locker.current_order_ref}
                                        </a>
                                    </p>
                                    <p style="margin-bottom: 5px;"><strong>Cliente:</strong> {$locker.current_customer}</p>
                                    {if $locker.current_pin}
                                        <p style="margin-bottom: 5px;"><strong>PIN:</strong> <code style="font-size: 16px;">{$locker.current_pin}</code></p>
                                    {/if}
                                    {if $locker.current_valid_until}
                                        <p class="text-muted" style="margin-bottom: 0;"><small>Válido: {$locker.current_valid_until|date_format:"%d/%m %H:%M"}</small></p>
                                    {/if}
                                {else}
                                    <p class="text-muted" style="margin-top: 20px; text-align: center;">
                                        <i class="icon-check" style="font-size: 24px;"></i><br>
                                        Lista para usar
                                    </p>
                                {/if}
                            </div>
                            {if $locker.status == 'occupied'}
                                <div class="panel-footer" style="padding: 8px 15px;">
                                    <a href="{$release_url}&id_locker={$locker.id_locker}" 
                                       class="btn btn-xs btn-warning"
                                       onclick="return confirm('¿Liberar esta taquilla?');">
                                        <i class="icon-unlock-alt"></i> Liberar
                                    </a>
                                </div>
                            {/if}
                        </div>
                    </div>
                {/foreach}
            {else}
                <div class="col-lg-12">
                    <div class="alert alert-info">
                        <i class="icon-info-circle"></i> 
                        {l s='No hay taquillas configuradas. Añade una a continuación.' mod='rkpickup'}
                    </div>
                </div>
            {/if}
        </div>
    </div>
</div>

{* Asignaciones activas *}
{if $active_assignments && count($active_assignments) > 0}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-list"></i> {l s='Asignaciones Activas' mod='rkpickup'}
    </div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Cliente</th>
                    <th>Taquilla</th>
                    <th>PIN</th>
                    <th>Estado</th>
                    <th>Válido hasta</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$active_assignments item=assign}
                    <tr>
                        <td>
                            <a href="{$order_link_base}&id_order={$assign.id_order}&vieworder" target="_blank">
                                #{$assign.order_reference}
                            </a>
                        </td>
                        <td>{$assign.customer_name}</td>
                        <td><strong>{$assign.locker_name}</strong></td>
                        <td><code style="font-size: 14px;">{$assign.pin_code}</code></td>
                        <td>
                            {if $assign.status == 'pending'}
                                <span class="badge badge-info">Pendiente</span>
                            {elseif $assign.status == 'ready'}
                                <span class="badge badge-success">Listo</span>
                            {/if}
                        </td>
                        <td>{$assign.valid_until|date_format:"%d/%m/%Y %H:%M"}</td>
                        <td>
                            <a href="{$collected_url}&id_order={$assign.id_order}" 
                               class="btn btn-xs btn-success"
                               onclick="return confirm('¿Marcar como recogido?');">
                                <i class="icon-check"></i> Recogido
                            </a>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
</div>
{/if}

{* Formulario añadir taquilla *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-plus"></i> {l s='Añadir taquilla' mod='rkpickup'}
    </div>
    
    <form method="post" action="{$add_locker_url}" class="form-horizontal">
        <div class="panel-body">
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Lock ID (TTLock)' mod='rkpickup'}</label>
                <div class="col-lg-4">
                    <input type="text" name="locker_lock_id" class="form-control" required 
                           placeholder="Ej: 12345678">
                    <p class="help-block">{l s='ID del candado en la app TTLock' mod='rkpickup'}</p>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Nombre' mod='rkpickup'}</label>
                <div class="col-lg-4">
                    <input type="text" name="locker_name" class="form-control" required
                           placeholder="Ej: Taquilla 1">
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">{l s='Descripción' mod='rkpickup'}</label>
                <div class="col-lg-4">
                    <input type="text" name="locker_description" class="form-control"
                           placeholder="Ej: Junto a la entrada">
                </div>
            </div>
        </div>
        <div class="panel-footer">
            <button type="submit" name="addLocker" class="btn btn-primary">
                <i class="process-icon-new"></i> {l s='Añadir taquilla' mod='rkpickup'}
            </button>
        </div>
    </form>
</div>

{* Historial reciente *}
{if $recent_history && count($recent_history) > 0}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-time"></i> {l s='Historial Reciente' mod='rkpickup'}
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Pedido</th>
                    <th>Taquilla</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$recent_history item=h}
                    <tr>
                        <td>{$h.date_upd|date_format:"%d/%m/%Y %H:%M"}</td>
                        <td>#{$h.order_reference}</td>
                        <td>{$h.locker_name}</td>
                        <td>
                            {if $h.status == 'picked_up'}
                                <span class="badge badge-success">Recogido</span>
                            {elseif $h.status == 'expired'}
                                <span class="badge badge-danger">Expirado</span>
                            {elseif $h.status == 'cancelled'}
                                <span class="badge badge-default">Cancelado</span>
                            {/if}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
</div>
{/if}
