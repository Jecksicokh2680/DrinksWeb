import imaplib
import email
from email.header import decode_header
import re
import json
from datetime import datetime
from zoneinfo import ZoneInfo
import email.utils

GMAIL_USER = "drinksdepotsede1@gmail.com"
GMAIL_PASS = "xksdbdnwzvpgmtea"

# Configurar la zona horaria de Bogotá
TZ_BOGOTA = ZoneInfo("America/Bogota")

lista_transferencias = []

try:
    # Conectarse a Gmail
    mail = imaplib.IMAP4_SSL("imap.gmail.com", 993)
    mail.login(GMAIL_USER, GMAIL_PASS)
    mail.select("INBOX")

    # Obtener fecha de hoy
    fecha_hoy = datetime.now(TZ_BOGOTA).strftime("%d-%b-%Y")

    # Buscar correos
    criterio_busqueda = f'(OR SUBJECT "Bre-B" SUBJECT "Detalle de tu venta" ON {fecha_hoy})'
    status, messages = mail.uid('search', None, criterio_busqueda)
    mail_ids = messages[0].split()

    if mail_ids:
        for mail_id in mail_ids:
            uid_correo = mail_id.decode('utf-8')

            # Descargar contenido
            status, data = mail.uid('fetch', mail_id, '(RFC822)')
            raw_email = data[0][1]
            msg = email.message_from_bytes(raw_email)

            # Asunto
            subject, encoding = decode_header(msg["Subject"])[0]
            if isinstance(subject, bytes):
                subject = subject.decode(encoding if encoding else 'utf-8', errors='ignore')

            # Fecha
            date_str = msg["Date"]
            try:
                fecha_parsed = email.utils.parsedate_to_datetime(date_str)
                fecha_bogota = fecha_parsed.astimezone(TZ_BOGOTA)
                fecha_correo = fecha_bogota.strftime('%Y-%m-%d %H:%M:%S')
            except:
                fecha_correo = datetime.now(TZ_BOGOTA).strftime('%Y-%m-%d %H:%M:%S')

            # Cuerpo
            body = ""
            if msg.is_multipart():
                for part in msg.walk():
                    if part.get_content_type() in ["text/plain", "text/html"]:
                        payload = part.get_payload(decode=True)
                        if payload: body += payload.decode('utf-8', errors='ignore') + "\n"
            else:
                payload = msg.get_payload(decode=True)
                if payload: body = payload.decode('utf-8', errors='ignore')

            texto_plano_limpio = " ".join(re.sub('<[^<]+?>', ' ', body).split())

            # --- EXTRACCIÓN DE DATOS ---
            # Monto
            match_monto = re.search(r'(?:Monto\s*:\s*\$\s*|Recibiste\s+)([\d.,]+)', texto_plano_limpio, re.IGNORECASE)
            monto = float(re.sub(r'[^\d]', '', match_monto.group(1))) if match_monto else 0.0

            # Referencia
            match_ref = re.search(r'Ref\s*[:.]\s*([A-Z0-9]+)', texto_plano_limpio, re.IGNORECASE)
            referencia = match_ref.group(1) if match_ref else "No detectado"

            # Pagador
            match_pagador = re.search(r'(?:Pagador\s*:\s*|de\s+)([\s\S]*?)(?:Banco|Referencia|el|$)', texto_plano_limpio, re.IGNORECASE)
            pagador = " ".join(match_pagador.group(1).strip().split()) if match_pagador else "No detectado"
            
            # --- CONTROL DE DUPLICADOS ---
            ya_existe = False
            for t in lista_transferencias:
                # Regla 1: Referencia única (Si son iguales, es el mismo movimiento)
                if referencia != "No detectado" and t['referencia'] == referencia:
                    ya_existe = True
                    break
                # Regla 2: Coincidencia de monto, pagador y minuto
                if (t['monto'] == monto and 
                    t['pagador'].lower() == pagador.lower() and 
                    t['fecha_correo'][:16] == fecha_correo[:16]):
                    ya_existe = True
                    break

            if not ya_existe and monto > 0:
                lista_transferencias.append({
                    'monto': monto,
                    'pagador': pagador,
                    'referencia': referencia,
                    'fecha_correo': fecha_correo,
                    'asunto': subject
                })

    mail.close()
    mail.logout()

except Exception as e:
    lista_transferencias = [{"error": str(e)}]

print(json.dumps(lista_transferencias))