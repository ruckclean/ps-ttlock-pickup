{**
 * Admin order page - pickup info
 *}

<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-header-title">
            <i class="material-icons">lock</i>
            {l s='Recogida en Taquilla' mod='rkpickup'}
        </h3>
    </div>
    <div class="card-body">
        {if $assignment}
            <div class="pickup-info">
                <p><strong>{l s='Taquilla:' mod='rkpickup'}</strong> {$assignment.locker_name}</p>
                <p><strong>{l s='Código PIN:' mod='rkpickup'}</strong> <code style="font-size: 1.5em; background: #f5f5f5; padding: 5px 10px; border-radius: 4px;">{$assignment.pin_code}</code></p>
                <p><strong>{l s='Estado:' mod='rkpickup'}</strong> 
                    {if $assignment.status == 'ready'}
                        <span class="badge badge-success">{l s='Listo para recoger' mod='rkpickup'}</span>
                    {elseif $assignment.status == 'picked_up'}
                        <span class="badge badge-info">{l s='Recogido' mod='rkpickup'}</span>
                    {elseif $assignment.status == 'expired'}
                        <span class="badge badge-warning">{l s='Expirado' mod='rkpickup'}</span>
                    {else}
                        <span class="badge badge-secondary">{$assignment.status}</span>
                    {/if}
                </p>
                <p><strong>{l s='Válido hasta:' mod='rkpickup'}</strong> {$assignment.valid_until|date_format:"%d/%m/%Y %H:%M"}</p>
            </div>
        {else}
            <div class="alert alert-info">
                {l s='Este pedido no tiene taquilla asignada.' mod='rkpickup'}
            </div>
        {/if}
    </div>
</div>
