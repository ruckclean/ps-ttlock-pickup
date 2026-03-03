{**
 * Order confirmation page - pickup info
 *}

<div class="card pickup-confirmation" style="background: linear-gradient(135deg, #00D4AA 0%, #00B894 100%); color: #1A1A1A; padding: 30px; border-radius: 15px; margin: 20px 0;">
    <h3 style="margin-bottom: 20px;">
        <i class="material-icons" style="vertical-align: middle;">check_circle</i>
        {l s='¡Tu pedido está listo para recoger!' mod='rkpickup'}
    </h3>
    
    <div style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
        <p style="margin: 5px 0;"><strong>{l s='Taquilla:' mod='rkpickup'}</strong> {$assignment.locker_name}</p>
        <p style="margin: 15px 0; font-size: 1.5em;">
            <strong>{l s='Tu código PIN:' mod='rkpickup'}</strong><br>
            <span style="font-family: monospace; font-size: 2em; background: #1A1A1A; color: #00D4AA; padding: 10px 20px; border-radius: 8px; display: inline-block; margin-top: 10px; letter-spacing: 5px;">
                {$assignment.pin_code}
            </span>
        </p>
        <p style="margin: 5px 0;"><strong>{l s='Válido hasta:' mod='rkpickup'}</strong> {$assignment.valid_until|date_format:"%d/%m/%Y %H:%M"}</p>
    </div>
    
    <div style="background: rgba(0,0,0,0.1); padding: 15px; border-radius: 10px;">
        <p style="margin: 0;"><strong><i class="material-icons" style="vertical-align: middle; font-size: 18px;">place</i> {l s='Dirección de recogida:' mod='rkpickup'}</strong></p>
        <p style="margin: 5px 0 0 0;">{$pickup_address}</p>
    </div>
    
    <p style="margin-top: 15px; font-size: 0.9em; opacity: 0.8;">
        {l s='También recibirás un email con estas instrucciones.' mod='rkpickup'}
    </p>
</div>
