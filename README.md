# BIND zone builder
Generates nicely formatted [BIND](http://en.wikipedia.org/wiki/BIND) DNS zone files from simple XML source definition documents. A minor rewrite of a utility I put together a few years back to simplify the management of various BIND zone instances I have under my control.

Automatically handles zone serial number increments when rewriting existing zone file(s) with new DNS record information and performs some sanity checks of source XML data (a minimum of one NS record per zone, IPv4 format checks) to hopefully avoid some basic errors.

Support for the following record types:

- **ANAME** - only IPv4 for now (could be extended to IPv6 quite easily)
- **CNAME**
- **MX**
- **NS** - ensure at least one name server defined
- **SPF** - provides a (very) simple SPF record builder - for anything more advanced a TXT record can be used
- **TXT**

Time/expiry can be formatted in seconds or the standard BIND units of weeks, days, hours, minutes. TTL's can be set for individual records as well, see [Configuration XML format](#configuration-xml-format) below.

## Requires
- PHP 5.4
- [XML Parser extension](http://www.php.net/manual/en/book.xml.php) (James Clark's expat), should be installed by default.

## Usage
Also shown by running `bindzonebuilder.php` without command line option(s).

	Usage: bindzonebuilder.php -c[file] -t[dir]

	  -c[file]    XML domain configuration file
	  -t[dir]     Target directory for generated bind zone files (must exist)

## Configuration XML format
An example XML config and output zone files, also refer to [example.xml](example.xml).

```xml
<zonelist>
	<domain name="domain01.com" admin="dns@domainadmin.com" ttl="1D" refresh="8H" retry="2H" expire="1W" negttl="60M">
		<record type="NS" value="ns1.domain01.com." />
		<record type="NS" value="ns2.domain01.com." />
		<record type="A" value="123.255.255.123" ttl="600" />
		<record type="A" domain="subdomain01" ttl="4H" value="123.255.255.255" />
		<record type="CNAME" domain="www" value="domain01.com." />
		<record type="MX" priority="10" value="mail1.server.com." />
		<record type="MX" priority="20" value="mail2.server.com." />
		<record type="MX" priority="30" value="mail3.server.com." />
		<record type="SPF" value="123.255.255.123,_spf.google.com" />
		<record type="TXT" value="This is a text record" />
	</domain>

	<domain name="domain02.com" admin="dns@domainadmin.com" ttl="1D" refresh="8H" retry="2H" expire="1W" negttl="60M">
		<record type="NS" value="ns1.domain02.com." />
		<record type="NS" value="ns2.domain02.com." />
		<record type="MX" priority="10" value="mail1.server.com." />
		<record type="SPF" value="123.255.255.123,_spf.google.com[~]" />
		<!--
		Other valid SPF tail 'all' modifiers allowed are [+], [?], [-]
		Refer to: http://en.wikipedia.org/wiki/Sender_Policy_Framework#Qualifiers
		Default if not specified is '?all'
		-->
	</domain>
</zonelist>
```

### /output/dir/db.domain01.com

	$TTL 1D
	@ IN SOA ns1.domain01.com. dns.domainadmin.com. (
							YYYYMMDDRR	; Serial
							8H			; Refresh
							2H			; Retry
							1W			; Expire
							60M )		; Negative Cache TTL

	@						NS			ns1.domain01.com.
	@						NS			ns2.domain01.com.

	@				600		A			123.255.255.123
	subdomain01		4H		A			123.255.255.255

	www						CNAME		domain01.com.

	@						MX 10		mail1.server.com.
	@						MX 20		mail2.server.com.
	@						MX 30		mail3.server.com.

	@						TXT			"v=spf1 ip4:123.255.255.123 include:_spf.google.com ?all"

	@						TXT			"This is a text record"

### /output/dir/db.domain02.com

	$TTL 1D
	@ IN SOA ns1.domain02.com. dns.domainadmin.com. (
							YYYYMMDDRR	; Serial
							8H			; Refresh
							2H			; Retry
							1W			; Expire
							60M )		; Negative Cache TTL

	@						NS			ns1.domain02.com.
	@						NS			ns2.domain02.com.

	@						MX 10		mail1.server.com.

	@						TXT			"v=spf1 ip4:123.255.255.123 include:_spf.google.com ~all"
