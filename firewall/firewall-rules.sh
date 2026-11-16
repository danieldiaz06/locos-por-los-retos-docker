#!/bin/bash
# Limpiar reglas existentes
iptables -F
# Permitir tráfico desde el host (red docker 172.25.0.0/16, el host es 172.25.0.1)
iptables -A INPUT -i eth0 -s 172.25.0.0/16 -j ACCEPT
# Bloquear todo el tráfico restante
iptables -A INPUT -j DROP
# Mostrar las reglas aplicadas
iptables -L -n
# Mantener el contenedor encendido (si no, se apaga al terminar el script)
tail -f /dev/null
