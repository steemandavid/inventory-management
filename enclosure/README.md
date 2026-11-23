# Pi enclosure / Dymo stand

Enclosure for the Raspberry Pi 1 Model B+ (labelpi) with the footprint of the Dymo LabelWriter 450
(127 x 186 mm, measured from photos), so the Dymo stands on top of it. Model: `pi_enclosure.scad`
(OpenSCAD, all dimensions are parameters at the top).

## Names

| Name | What it is |
|------|------------|
| **base** | bottom part, printed flat: Pi standoffs, port fillers, locating rim, screw holes with counterbores underneath |
| **cover** | upper part, printed upside down: top panel, back wall, side walls, front, partitions and screw posts |
| **top panel** | the flat top of the cover; the Dymo stands on it |
| **partitions** | inner walls of the cover, separating the Pi compartment from the cable pocket |
| **screw posts** | posts in the cover with heat-set inserts; the base is screwed to them from below |
| **Pi standoffs** | posts on the base with heat-set inserts; the Pi is screwed to them |
| **port openings** | openings in the back wall for the USB and RJ45 ports, open at the bottom |
| **power hole** | closed hole in the back wall for the micro-USB power plug |
| **pocket opening** | opening in the back wall into the cable pocket |
| **port fillers** | blocks on the base that close the port openings below the ports |
| **locating rim** | low rim on the base that fits just inside the cover's walls |
| **Pi compartment / power cable channel / cable pocket** | the spaces inside (see the layout below) |

## Files

| File | Print | Notes |
|------|-------|-------|
| `insert_test.stl` | first, a few minutes | insert test coupon: holes 3.0 to 3.4 mm, 4.5 mm deep. Pick the one where your M2.5 insert (3.5 mm OD x 3 mm) goes in nicely and set `insert_d` (default 3.2) |
| `base.stl` | flat, as exported | |
| `cover.stl` | upside down, as exported (top panel on the build plate) | the screw posts point up while printing, so the inserts go in from above |

Both parts print without supports. PLA or PETG, 0.2 mm layers, 3 walls, 15 % infill (top panel: 5 top layers).

## Layout

```
 back wall, seen from behind:   [ pocket opening ]  [power hole]  [RJ45] [USB] [USB]

 inside, seen from above (back wall at the top):
 +----------------------------------------------------------+
 | [USB][USB][RJ45]   (power hole)   o  |                    |
 |  Pi compartment      |  power cable   |   cable pocket     |
 |  Raspberry Pi B+     |  channel       |   (35 mm wide,     |
 |  (GPIO header left)  |                |    opens to the    |
 |              power plug ->            |    back)           |
 |o--------------------------------------+ <- partitions    o|
 |                cable pocket (front part)                   |
 |   o                                                    o   |
 +----------------------------------------------------------+   (o = screw post)
```

- **USB and RJ45** are flush with the back wall (0.5 mm recessed): plug in from outside. With the
  components up and the ports at the back, the Pi's RJ45 and micro-USB power connector are on the right.
- **Power hole** (14 x 10 mm): push the micro-USB plug through it and plug it into the Pi inside. The cable
  runs through the power cable channel. Only needed once, with the cover off.
- **Cable pocket:** the compartment on the right plus the space in front of the Pi. The pocket opening is the
  pocket's full width (~35 mm) and 28 mm high (full inner height minus a 2 mm strip under the top panel).
  Coil the spare USB-A to USB-B cable into a long loop and push it in from behind.
- **Screw posts:** 5, with 5 x M2.5 x 5 screws from below: the two front corners, one on each side wall
  (opposite each other) and one at the back between the power hole and the pocket opening.

## Assembly

1. Press 5 M2.5 heat-set inserts (3.5 mm OD x 3 mm) into the screw posts of the cover and 4 into the Pi
   standoffs of the base, flush with the surface. The holes are 4.5 mm deep: an M2.5 x 5 screw reaches
   ~3 mm into a screw post, the full length of the insert.
2. Screw the Pi onto the Pi standoffs (M2.5 x 5), ports towards the back.
3. Push the power cable through the power hole from outside and plug it into the Pi.
4. Lower the cover over the base (the port openings are open at the bottom, so it slides over the ports;
   the port fillers close them below the ports) and screw it on from below with 5 x M2.5 x 5. The heads sink
   into the counterbores in the base (3.4 mm wide, 1 mm deep, screw holes 2.7 mm).
5. Put the Dymo on the top panel. Stick-on rubber feet can go anywhere under the base (there are no
   recesses for them: those didn't print well).

## Notes

- The back corners have a 5 mm radius (the Dymo's are ~20 mm), so the back wall is straight where the ports are.
- Port positions follow the official Pi B+ drawing (RJ45 at 10.25 mm, USB at 29 and 47 mm from the power edge).
  To check the port openings before the full print, print only the first 8 to 10 mm of `cover.stl`
  (the top panel plus the start of the walls) and hold it over the Pi.

Regenerate the STLs:
```bash
for p in base cover insert_test; do openscad -D "part=\"$p\"" -o $p.stl pi_enclosure.scad; done
```
