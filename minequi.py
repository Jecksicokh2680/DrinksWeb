import imaplib
import email
from email.header import decode_header
import re
import json
from datetime import datetime
from zoneinfo import ZoneInfo

# Configuración
GMAIL_USER = "drinksdepotsede1@gmail.com"
GMAIL_PASS = "xksdbdnwzvpgmtea"
TZ_BOGOTA = ZoneInfo("America/Bogota")

def procesar_correos():
    lista_transferencias = []
    try:
        mail = imaplib.IMAP4_SSL("imap.gmail.com", 993)
        mail.login(GMAIL_USER, GMAIL_PASS)
        mail.select("INBOX")

        fecha_hoy = datetime.now(TZ_BOGOTA).strftime("%d-%b-%Y")
        criterio = f'(OR SUBJECT "Bre-B" SUBJECT "Detalle de tu venta" ON {fecha_hoy})'
        _, messages = mail.uid('search', None, criterio)
        mail_ids = messages[0].split()

        for mail_id in mail_ids:
            uid_correo = mail_id.decode('utf-8')
            _, data = mail.uid('fetch', mail_id, '(RFC822)')
            msg = email.message_from_bytes(data[0][1])

            # Obtención de datos básicos
            subject, _ = decode_header(msg["Subject"])[0]
            if isinstance(subject, bytes):
                subject = subject.decode('utf-8', errors='ignore')

            # Cuerpo del mensaje
            body = ""
            if msg.is_multipart():
                for part in msg.walk():
                    if part.get_content_type() in ["text/plain", "text/html"]:
                        payload = part.get_payload(decode=True)
                        if payload: body += payload.decode('utf-8', errors='ignore') + "\n"
            else:
                payload = msg.get_payload(decode=True)
                if payload: body = payload.decode('utf-8', errors='ignore')

            texto = " ".join(re.sub('<[^<]+?>', ' ', body).split())

            # 1. Monto
            monto = 0.0
            match_monto = re.search(r'(?:Monto\s*:\s*\$\s*|Recibiste\s+)([\d.,]+)', texto, re.IGNORECASE)
            if match_monto:
                monto_limpio = re.sub(r'[^\d]', '', match_monto.group(1))
                if monto_limpio: monto = float(monto_limpio)

            # 2. Pagador
            pagador = "No detectado"
            match_pag = re.search(r'Pagador\s*:\s*([\s\S]*?)(?:Banco|Referencia|$)', texto, re.IGNORECASE)
            if match_pag: pagador = " ".join(match_pag.group(1).split())[:40]

            # 3. Transacción y Celular (CORRECCIÓN AQUÍ)
            num_trans = "No detectado"
            celular = "No detectado"
            match_trans = re.search(r'N[uú]mero\s+de\s+transacci[oó]n\s*:\s*([A-Z0-9]{10,})', texto, re.IGNORECASE)
            if match_trans:
                num_trans = match_trans.group(1).strip()
                # Corrección: extraer últimos 10 caracteres y validar
                sub_cel = num_trans[-10:]
                if sub_cel.isdigit() and sub_cel.startswith('3'):
                    celular = sub_cel
            
            if celular == "No detectado":
                match_cel = re.search(r'\b3\d{9}\b', texto)
                if match_cel: celular = match_cel.group(0)

            # Agregar a la lista
            if monto > 0:
                lista_transferencias.append({
                    'id_unico': f"{monto}_{datetime.now(TZ_BOGOTA).strftime('%Y%m%d%H%M')}",
                    'uid_correo': uid_correo,
                    'monto': monto,
                    'celular': celular,
                    'pagador': pagador,
                    'banco_origen': 'Nequi',
                    'fecha_correo': datetime.now(TZ_BOGOTA).strftime('%Y-%m-%d %H:%M:%S'),
                    'asunto': subject
                })

        mail.close()
        mail.logout()
    except Exception as e:
        return json.dumps([{"error": str(e)}])

    return json.dumps(lista_transferencias)

print(procesar_correos())