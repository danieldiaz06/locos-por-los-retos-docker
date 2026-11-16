# Actividad – Locos por los Retos de Docker

Laboratorio de pentesting montado con **Docker Compose**: dos servidores web, un firewall con `iptables` y una máquina atacante/administradora con **Parrot Security OS**, todos en la misma red interna. Desde Parrot se rastrea la red con `nmap`, se captura el tráfico con `tshark`/Wireshark, se añade una página PHP de geolocalización y por último se publican las imágenes en Docker Hub y el compose en GitHub.

- **Repositorio:** https://github.com/danieldiaz06/locos-por-los-retos-docker
- **Docker Hub:** https://hub.docker.com/u/daiaz

## Entorno

- Windows 11 + **Docker Desktop** (Docker 29.8.2, Compose v5.6.0)
- **WSL 2 – Ubuntu** con la integración de Docker Desktop activada (todo el trabajo se hace en `~/red1`)

![Docker funcionando en WSL](img/Pasted%20image%2020261116194655.png)

## Estructura del proyecto

```
red1/
├── docker-compose.yml          # Levanta los 4 contenedores en la red mynetwork
├── .gitignore                  # Excluye capturas/ (.pcap)
├── httpd/
│   ├── Dockerfile-httpd        # web2: Apache (httpd)
│   ├── Dockerfile-web1         # web1: Apache + PHP (php:8.2-apache)
│   └── public-html/
│       ├── index.html
│       └── ubicacion.php       # Página de geolocalización
├── firewall/
│   ├── Dockerfile-firewall     # Ubuntu + iptables
│   └── firewall-rules.sh       # Reglas del firewall
└── parrotsec/
    └── Dockerfile-parrot       # Parrot Security OS + herramientas
```

## Topología de red

Red `mynetwork` (bridge) – subred **172.25.0.0/16**

| Contenedor | IP | Imagen Docker Hub | Puerto en el host |
|---|---|---|---|
| host / gateway | 172.25.0.1 | – | – |
| web1 | 172.25.0.2 | `daiaz/web1` | 8080 |
| web2 | 172.25.0.3 | `daiaz/web2` | 8082 |
| firewall | 172.25.0.4 | `daiaz/firewall` | – |
| parrot | 172.25.0.5 | `daiaz/parrotsec` | – |

> **Cambio respecto al enunciado:** el PDF usa `172.20.0.0/16`, pero esa subred ya la ocupaba otra red Docker del equipo (`juegos-copia-net`) y el compose fallaba con `Pool overlaps with other one on this address space`. Se cambió a `172.25.0.0/16` (ver [Problemas encontrados](#problemas-encontrados)).

## Uso rápido

```bash
git clone https://github.com/danieldiaz06/locos-por-los-retos-docker.git
cd locos-por-los-retos-docker
docker compose up -d          # o docker compose up --build -d para construir en local
docker exec -it parrot bash   # entrar en la máquina de pentesting
```

---

## Paso 1 – Red con servidores web y firewall

Se crea el directorio del proyecto con las carpetas `httpd` y `firewall`.

![Crear carpetas](img/Pasted%20image%2020261116194826.png)

**Dockerfile-httpd**: copia la carpeta `public-html` al directorio de documentos de Apache (`/usr/local/apache2/htdocs/`).

![Dockerfile-httpd](img/Pasted%20image%2020261116194840.png)

**Dockerfile-firewall**: Ubuntu con `iptables` que ejecuta el script de reglas al arrancar.

![Dockerfile-firewall](img/Pasted%20image%2020261116194852.png)

**firewall-rules.sh**: limpia las reglas, acepta el tráfico de la red Docker y descarta el resto.

![firewall-rules.sh](img/Pasted%20image%2020261116194909.png)

> **Cambios respecto al enunciado:**
> - La red de origen permitida es la de Docker (el host, visto desde el contenedor, es el gateway de la red) en lugar de `192.168.1.0/24`.
> - Se añade `tail -f /dev/null` al final: sin él, el script termina, el `ENTRYPOINT` acaba y el contenedor se apaga.

**docker-compose.yml** inicial con web1, web2 y firewall, cada uno con IP fija en `mynetwork`:

![docker-compose.yml](img/Pasted%20image%2020261116194935.png)
![Estructura y compose](img/Pasted%20image%2020261116194951.png)

```bash
docker compose up --build -d
```

![Build](img/Pasted%20image%2020261116195017.png)

El primer arranque falló por el solapamiento de subred (resuelto en el paso 3 cambiando a 172.25.0.0/16):

![Error Pool overlaps](img/Pasted%20image%2020261116195028.png)

### Pruebas de conexión

Con Docker Desktop los contenedores viven dentro de una máquina virtual, así que desde Windows/WSL no se alcanza directamente 172.25.0.x. Las pruebas se hacen con un contenedor `alpine` dentro de la misma red, y el host se alcanza con `host.docker.internal`:

```bash
docker exec web1 sh -c 'echo "<h1>Server 1</h1>" > /var/www/html/index.html'   # web1 usa php:apache desde el paso 7
docker exec web2 sh -c 'echo "<h1>Server 2</h1>" > /usr/local/apache2/htdocs/index.html'
docker logs firewall
docker run --rm --network red1_mynetwork alpine sh -c "ping -c2 172.25.0.2; ping -c2 172.25.0.4; wget -qO- 172.25.0.2; wget -qO- 172.25.0.3; ping -c2 host.docker.internal"
```

<!-- 📸 PEGAR AQUÍ: captura de docker logs firewall (reglas iptables) -->
<!-- 📸 PEGAR AQUÍ: captura de los pings y del "Server 1" / "Server 2" -->

---

## Paso 2 – Contenedor Parrot Security OS

Se crea la carpeta `parrotsec` con el `Dockerfile-parrot` (ya con el nombre que pide el paso 3):

![Dockerfile-parrot](img/Pasted%20image%2020261116195336.png)
![cat Dockerfile-parrot](img/Pasted%20image%2020261116195344.png)

> **Cambios respecto al enunciado:**
> - `ENV DEBIAN_FRONTEND=noninteractive`, porque la instalación de Wireshark hace una pregunta interactiva que bloquearía el build.
> - Se añaden `tshark`, `curl`, `iputils-ping` e `iproute2`, necesarios en los pasos 3 a 6.
> - El Dockerfile del PDF termina en `wget &&` y con el `CMD` comentado, lo que da error de sintaxis. Se cierra con `&& apt-get clean`, `WORKDIR /root` y `CMD ["bash"]`.

```bash
docker build -t parrot-pentest -f Dockerfile-parrot .
docker run -it --rm parrot-pentest
```

![docker build](img/Pasted%20image%2020261116195409.png)

Al ejecutarlo de forma independiente, Parrot queda en la red `bridge` por defecto (**172.17.0.2**), separado de los servidores web. Esto es lo que se corrige en el paso 3:

![Parrot en 172.17.0.2](img/Pasted%20image%2020261116201019.png)

---

## Paso 3 – Todo el laboratorio en un único compose

Se modifica el `docker-compose.yml` para levantar los cuatro contenedores en `mynetwork`. Parrot se define como máquina administradora:

- `stdin_open: true` y `tty: true`: terminal interactivo.
- `cap_add: SYS_ADMIN, NET_ADMIN, NET_RAW`: privilegios para gestionar la red, y `NET_RAW` para que `nmap -O`/`-sS` y `tshark` puedan usar paquetes en crudo.

![compose down](img/Pasted%20image%2020261116201359.png)
![compose con parrot](img/Pasted%20image%2020261116203059.png)

Al levantarlo volvió a aparecer el conflicto de subred:

![Pool overlaps de nuevo](img/Pasted%20image%2020261116203111.png)

Se cambió la subred a **172.25.0.0/16** en el compose y en `firewall-rules.sh`:

```bash
sed -i 's/172\.20\./172.25./g' docker-compose.yml firewall/firewall-rules.sh
docker compose up --build -d
```

![compose up](img/Pasted%20image%2020261116203338.png)
![4 contenedores Up](img/Pasted%20image%2020261116203345.png)

Desde Parrot se descubren todos los hosts de la red (`.1` gateway, `.2` web1, `.3` web2, `.4` firewall, `.5` parrot):

```bash
docker exec -it parrot bash
ip a
nmap -sn 172.25.0.0/24
```

![nmap -sn](img/Pasted%20image%2020261116203452.png)

---

## Paso 4 – Rastreos con nmap

Todos se ejecutan dentro del contenedor Parrot.

**1. Escaneo básico**: `nmap 172.25.0.2`. Puerto 80/tcp abierto (http).

![nmap básico](img/Pasted%20image%2020261116203527.png)

**2. Todos los puertos**: `nmap -p- 172.25.0.2`. Solo el 80 de los 65535.

![nmap -p-](img/Pasted%20image%2020261116203534.png)

**3. Versiones de servicios**: `nmap -sV 172.25.0.2`. **Apache httpd 2.4.71 (Unix)**.

![nmap -sV](img/Pasted%20image%2020261116203541.png)

**4. Sistema operativo**: `nmap -O 172.25.0.2`. No hay coincidencia exacta (contenedor sin kernel propio), pero el fingerprint indica `x86_64-pc-linux-gnu` a 1 salto de red.

![nmap -O](img/Pasted%20image%2020261116203615.png)

**5. Subred completa**: `nmap 172.25.0.0/24`. web1 y web2 con el 80 abierto; firewall y parrot sin puertos abiertos. En el gateway (172.25.0.1) aparecen puertos del host, como el 8081 de otra práctica.

![nmap subred](img/Pasted%20image%2020261116203637.png)

**6. Puertos específicos**: `nmap -p 80,443 172.25.0.2`. 80 abierto y 443 cerrado (no hay HTTPS).

![nmap -p 80,443](img/Pasted%20image%2020261116203702.png)

**7. Sin descubrimiento de hosts**: `nmap -Pn 172.25.0.2`.

![nmap -Pn](img/Pasted%20image%2020261116203713.png)

**8. Escaneo avanzado**: `nmap -sS -sV -O -T4 172.25.0.0/24` (SYN sigiloso + versiones + SO + velocidad alta).

<!-- 📸 PEGAR AQUÍ: captura de nmap -sS -sV -O -T4 172.25.0.0/24 -->

**9. Scripts NSE de vulnerabilidades SMB**: `nmap --script "smb-vuln*" 172.25.0.2`. Sin resultados, porque web1 no tiene SMB (puerto 445). Es el resultado esperado.

![nmap smb-vuln](img/Pasted%20image%2020261116203754.png)

---

## Paso 5 – Captura de tráfico con tshark dentro de Parrot

Interfaz de red del contenedor: `eth0` con 172.25.0.5.

![ip a parrot](img/Pasted%20image%2020261116203851.png)

**Tráfico ICMP**: en un terminal `tshark -i eth0 icmp` y en otro `ping -c 4 172.25.0.3`:

![tshark icmp](img/Pasted%20image%2020261116203904.png)
![ping](img/Pasted%20image%2020261116203934.png)
![Echo request/reply capturados](img/Pasted%20image%2020261116203959.png)

**Tráfico HTTP**: `tshark -i eth0 port 80` mientras se hace `curl` a web1 y web2. Se ve el saludo TCP (SYN, SYN-ACK, ACK), el `GET / HTTP/1.1` y la respuesta `HTTP/1.1 200 OK`:

![curl](img/Pasted%20image%2020261116204013.png)
![tshark port 80](img/Pasted%20image%2020261116204018.png)

> El `docker exec -it web1 curl http://172.20.0.3` del enunciado no funciona: la imagen `httpd` no incluye `curl`. El tráfico se genera desde Parrot.

---

## Paso 6 – Captura desde fuera de la subred (host)

Con Docker Desktop, el puente de la red (`br-xxxx`) está dentro de la VM de Docker, no en Windows ni en WSL. Para capturar "desde el host" se usa un contenedor con `--net=host`, que comparte las interfaces de esa VM, y se guarda la captura en un `.pcap` que luego se analiza con Wireshark.

Identificar el gateway y la interfaz bridge de `mynetwork` (`br-` + los 12 primeros caracteres del ID de la red → `br-9c0918eed14c`):

```bash
docker network inspect red1_mynetwork | grep -E "Gateway|Subnet"
docker run --rm --net=host parrot-pentest ip -br a | grep br-
```

![Gateway](img/Pasted%20image%2020261116204344.png)

Captura de 60 segundos sobre el bridge mientras se genera tráfico desde Parrot (pings y curl a web1 y web2):

```bash
docker run --rm --net=host --cap-add NET_ADMIN --cap-add NET_RAW -v ~/red1/capturas:/cap parrot-pentest \
  sh -c "tshark -i br-9c0918eed14c -a duration:60 -w /cap/captura.pcap; chmod 644 /cap/captura.pcap"
```

![Generar tráfico](img/Pasted%20image%2020261116204503.png)
![Captura en marcha](img/Pasted%20image%2020261116204842.png)
![40 paquetes capturados](img/Pasted%20image%2020261116205021.png)

Análisis en Wireshark (`wireshark ~/red1/capturas/captura.pcap`) con los filtros del enunciado:

**`ip.addr == 172.25.0.0/16`**: todo el tráfico de la red Docker.

![Filtro subred](img/Pasted%20image%2020261116205110.png)

**`tcp.port == 80`**: conexiones HTTP entre Parrot y los servidores web.

![Filtro tcp.port 80](img/Pasted%20image%2020261116205123.png)

**`icmp`**: los pings.

![Filtro icmp](img/Pasted%20image%2020261116205140.png)

**`http`**: las peticiones `GET /` y las respuestas `200 OK` de web1 y web2.

![Filtro http](img/Pasted%20image%2020261116205200.png)

---

## Paso 7 – PHP en web1: página de geolocalización

La imagen `httpd` no incluye PHP. Para "instalar PHP para Apache" en web1 se usa la imagen oficial **`php:8.2-apache`** (Apache con `mod_php` ya integrado) en un Dockerfile propio, `Dockerfile-web1`. web2 sigue con `httpd`.

`ubicacion.php` consulta la API de **ip-api.com** con la IP del cliente (`$_SERVER['REMOTE_ADDR']`). Como las peticiones llegan desde la IP privada del gateway Docker (172.25.0.1) y ip-api no puede localizar IPs privadas, en ese caso se consulta la IP pública de salida.

> El código del enunciado tiene comillas tipográficas (`«»`, `‘’`) que rompen el PHP. Se ha corregido.

Se publican los puertos en el compose: web1 en **8080** y web2 en **8082** (el 8081 del enunciado ya lo usaba otra práctica).

![Dockerfile-web1, ubicacion.php y compose](img/Pasted%20image%2020261116205259.png)

```bash
docker compose down
docker compose up --build -d
```

![compose down/up](img/Pasted%20image%2020261116205322.png)
![Puertos publicados](img/Pasted%20image%2020261116205344.png)

`http://localhost:8080/ubicacion.php` y `http://localhost:8082`:

> La página muestra la IP del cliente (172.25.0.1, el gateway Docker), indica que es privada y devuelve país, región y ciudad de la IP pública de salida. (Captura omitida en el repositorio por privacidad.)
![web2](img/Pasted%20image%2020261116205407.png)

---

## Paso 8 – Publicar en Docker Hub y GitHub

Se añade `image: daiaz/<servicio>:latest` a cada servicio del compose, para que `docker compose build` etiquete las imágenes con el usuario de Docker Hub.

```bash
docker login
docker compose build
docker images | grep daiaz
```

![docker login](img/Pasted%20image%2020261116205616.png)
![compose con image](img/Pasted%20image%2020261116205630.png)
![Imágenes etiquetadas](img/Pasted%20image%2020261116205639.png)

> `docker compose build` construye y etiqueta las cuatro imágenes de una vez. Los `docker build -f Dockerfile-...` del enunciado fallan porque la ruta de `-f` es relativa al directorio actual y no al contexto.

```bash
docker push daiaz/web1:latest
docker push daiaz/web2:latest
docker push daiaz/firewall:latest
docker push daiaz/parrotsec:latest
```

![docker push](img/Pasted%20image%2020261116205712.png)

<!-- 📸 PEGAR AQUÍ: push de parrotsec terminado y perfil de hub.docker.com/u/daiaz con los 4 repositorios -->

Docker Hub no guarda el `docker-compose.yml`, así que el proyecto se sube a GitHub:

![Crear repositorio](img/Pasted%20image%2020261116210137.png)

```bash
git init
git add .
git commit -m "Lab de pentesting con Docker Compose"
git remote add origin https://github.com/danieldiaz06/locos-por-los-retos-docker.git
git push -u origin main
```

![git push](img/Pasted%20image%2020261116210245.png)

---

## Paso 9 – Desplegar el compose desde el repositorio

Se para el laboratorio original y se clona el repositorio en una carpeta nueva. Al clonar solo se descarga la configuración (yml y Dockerfiles); `docker compose up` reconstruye el laboratorio completo usando las imágenes `daiaz/*`.

```bash
cd ~/red1 && docker compose down
mkdir -p ~/companero && cd ~/companero
git clone https://github.com/danieldiaz06/locos-por-los-retos-docker.git
cd locos-por-los-retos-docker
docker compose up -d
docker ps
```

![compose down](img/Pasted%20image%2020261116210355.png)
![git clone](img/Pasted%20image%2020261116210451.png)
![Laboratorio desplegado desde el repo](img/Pasted%20image%2020261116210459.png)

> Como lo que se descarga es el yml, se puede modificar antes del `up`. Por ejemplo, si el compose de un compañero usa la subred 172.20 o el puerto 8081, habría que cambiarlos en este equipo por los conflictos ya vistos.

---

## Problemas encontrados

| Problema | Causa | Solución |
|---|---|---|
| `Pool overlaps with other one on this address space` | La subred 172.20.0.0/16 ya la usaba otra red Docker (`juegos-copia-net`) | Cambiar la subred del laboratorio a 172.25.0.0/16 |
| El firewall se apaga nada más arrancar | El `ENTRYPOINT` es un script que termina | `tail -f /dev/null` al final del script |
| `ping`/`curl` a 172.25.0.x desde WSL no responde | Con Docker Desktop la red está dentro de su VM | Pruebas desde contenedores de la misma red |
| `docker exec web1 curl` falla | La imagen `httpd` no trae `curl` | Generar el tráfico desde Parrot |
| No hay interfaz `br-…` en WSL para Wireshark | El bridge está en la VM de Docker Desktop | Capturar con un contenedor `--net=host` y abrir el `.pcap` en Wireshark |
| `tshark: ... could not be opened: Permission denied` | La carpeta montada no permitía escribir al contenedor | `chmod 777 ~/red1/capturas` |
| La imagen `httpd` no ejecuta PHP | No incluye el módulo PHP | Imagen `php:8.2-apache` para web1 |
| Puerto 8081 ocupado | Lo usa otra práctica | web2 publicado en 8082 |
| `remote: Repository not found` al hacer push | El repositorio aún no existía en GitHub | Crearlo vacío en github.com/new |

![Permission denied tshark](img/Pasted%20image%2020261116204413.png)
![Repository not found](img/Pasted%20image%2020261116205939.png)

## Conclusión

Con un único `docker-compose.yml` se levanta un laboratorio completo y reproducible: servidores objetivo, un firewall y una máquina de pentesting en una red aislada con IPs fijas. Desde Parrot se ha hecho el reconocimiento de la red (descubrimiento de hosts, puertos, versiones y SO con nmap) y se ha analizado el tráfico tanto desde dentro de la red (tshark) como desde el host (captura del bridge y Wireshark). Al publicar las imágenes en Docker Hub y la configuración en GitHub, cualquiera puede desplegar el mismo entorno con `git clone` y `docker compose up`.
