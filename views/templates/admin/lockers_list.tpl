{**
 * Lockers list and add form
 *}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-inbox"></i> {l s='Taquillas configuradas' mod='rkpickup'}
    </div>
    
    <div class="panel-body">
        {if $lockers && count($lockers) > 0}
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>{l s='ID' mod='rkpickup'}</th>
                        <th>{l s='Nombre' mod='rkpickup'}</th>
                        <th>{l s='Lock ID (TTLock)' mod='rkpickup'}</th>
                        <th>{l s='Estado' mod='rkpickup'}</th>
                        <th>{l s='Asignaciones activas' mod='rkpickup'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$lockers item=locker}
                        <tr>
                            <td>{$locker.id_locker}</td>
                            <td><strong>{$locker.name}</strong></td>
                            <td><code>{$locker.lock_id}</code></td>
                            <td>
                                {if $locker.status == 'available'}
                                    <span class="badge badge-success">{l s='Disponible' mod='rkpickup'}</span>
                                {elseif $locker.status == 'occupied'}
                                    <span class="badge badge-warning">{l s='Ocupada' mod='rkpickup'}</span>
                                {else}
                                    <span class="badge badge-danger">{l s='Mantenimiento' mod='rkpickup'}</span>
                                {/if}
                            </td>
                            <td>{$locker.active_assignments}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {else}
            <div class="alert alert-info">
                {l s='No hay taquillas configuradas. Añade una a continuación.' mod='rkpickup'}
            </div>
        {/if}
    </div>
</div>

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
                    <p class="help-block">{l s='ID del candado en la app TTLock (ver detalles del candado)' mod='rkpickup'}</p>
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
                           placeholder="Ej: Taquilla junto a la entrada">
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
